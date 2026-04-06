#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Postgres Database User Upload script.
 */

$usage = "
Help information for this script.

Usage:
    php user_upload.php [options]

Options:
    --help              Display this help message
    --file [csv file]   CSV file to parse and insert into the database
    --create_table      Create or rebuild the Postgres users table
    --dry_run           Run the script without altering the database
    -u                  Postgres username
    -p                  Postgres password
    -h                  Postgres host

Examples:
    php user_upload.php --create_table -u dbuser -p dbpass -h localhost
    php user_upload.php --file users.csv -u dbuser -p dbpass -h localhost
    php user_upload.php --file users.csv --dry_run
";

$options = retrieve_parsed_arguments();

if ($options['help']) {
    echo $usage;
    exit(0);
}

try {
    if ($options['create_table']) {
        create_users_table($options);
        exit(0); // No further action after this directive.
    }

    if (!empty($options['file'])) {
        process_csv_file($options);
    }
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);

/**
 * Parse command-line arguments.
 *
 * @return array Options from the command line.
 */
function retrieve_parsed_arguments(): array {
    // Parse known options from the command line.
    $parsed = getopt('u:p:h:', ['help', 'file:', 'create_table', 'dry_run']);

    // Map parsed results to options.
    $options = [
        'help' => isset($parsed['help']),
        'file' => $parsed['file'] ?? null,
        'create_table' => isset($parsed['create_table']),
        'dry_run' => isset($parsed['dry_run']),
        'u' => $parsed['u'] ?? null,
        'p' => $parsed['p'] ?? null,
        'h' => $parsed['h'] ?? null,
    ];
    return $options;
}

/**
 * Create or rebuild the users table in the PostgreSQL database.
 *
 * @param array $options The parsed options containing database connection info.
 */
function create_users_table(array $options): void {
    // Check DB options for username, password, and host.
    if (!valid_db_options($options)) {
        throw new Exception("Error: Missing database connection options (-u, -p, -h are required).");
    }

    $pdo = db_connect($options);
    $query = "DROP TABLE IF EXISTS users;
            CREATE TABLE users (
                name VARCHAR(255) NOT NULL,
                surname VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE
            );";

    $pdo->exec($query);

    echo "Users table created successfully.\n";
}

/**
 * Process the CSV file and pass valid rows for database insertion or dry run.
 *
 * @param array $options The parsed options containing file and database info.
 */
function process_csv_file(array $options): void {
    $filename = $options['file'];

    if (!is_file($filename) || !is_readable($filename) ||
        strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
        throw new Exception(
            "Error: File '{$filename}' is not a readable CSV file."
            . " Check CSV file exists and has the correct permissions."
        );
    }

    // Before processing, normalize the names and emails.
    $filedata = normalize_csv_data($filename);

    process_user_data($filedata, $options);
}

/**
 * Normalize the CSV data for correct format and required columns.
 *
 * @param string $filename The CSV file name.
 * @return array Normalized rows containing valid name, surname, email.
 */
function normalize_csv_data(string $filename): array {
    $file = fopen($filename, 'r');
    if ($file === false) {
        throw new Exception("Error: Unable to open file '{$filename}'.");
    }

    // Read and validate the header row.
    $header = fgetcsv($file);
    if ($header === false || $header === [null]) {
        fclose($file);
        throw new Exception("Error: CSV file is empty or unreadable.");
    }
    $header = array_map(fn($col) => strtolower(trim($col)), $header);
    if ($header !== ['name', 'surname', 'email']) {
        fclose($file);
        throw new Exception("Error: CSV header must contain 'name', 'surname', 'email'.");
    }

    // Read and normalize each row.
    $rows = [];
    while (($row = fgetcsv($file)) !== false) {
        if (count($row) !== 3) {
            echo "Malformed row — skipping.\n";
            continue;
        }
        $name = ucwords(strtolower(trim($row[0])), " \t\r\n\f\v-'");
        $surname = ucwords(strtolower(trim($row[1])), " \t\r\n\f\v-'");
        $email = strtolower(trim($row[2]));

        // Skip rows with empty name, surname, or email.
        if ($name === '' || $surname === '' || $email === '') {
            echo "Incomplete row — skipping.\n";
            continue;
        }

        // Validate the email address.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "Invalid email '{$email}' for {$name} {$surname} — skipping.\n";
            continue;
        }

        $rows[] = [
            'name' => $name,
            'surname' => $surname,
            'email' => $email,
        ];
    }
    fclose($file);

    return $rows;
}

/**
 * Connect to the PostgreSQL database.
 *
 * @param array $options The database connection options (u, p, h).
 * @return PDO The database connection.
 */
function db_connect(array $options): PDO {
    $dsn = sprintf("pgsql:host=%s", $options['h']);

    return new PDO($dsn, $options['u'], $options['p'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/**
 * Process user data by inserting into the database, or displaying in dry run mode.
 *
 * @param array $filedata The normalized data from the CSV file.
 * @param array $options The parsed options containing database and dry run info.
 */
function process_user_data(array $filedata, array $options): void {
    if ($options['dry_run']) {
        echo "Dry run mode: No changes will be made to the database.\n";
        foreach ($filedata as $row) {
            echo "{$row['name']} {$row['surname']} ({$row['email']})\n";
        }
        echo "Dry run complete: " . count($filedata) . " rows to be inserted.\n";
        return;
    }

    // Check DB options for username, password, and host.
    if (!valid_db_options($options)) {
        throw new Exception("Error: Missing database connection options (-u, -p, -h are required).");
    }

    $pdo = db_connect($options);

    // Verify the users table exists before inserting.
    $pdo->query('SELECT 1 FROM users LIMIT 1');

    $sql = $pdo->prepare(
        'INSERT INTO users (name, surname, email) VALUES (:name, :surname, :email) ON CONFLICT (email) DO NOTHING'
    );

    $inserted = 0;

    // Since the user list could be large, use a transaction.
    $pdo->beginTransaction();
    foreach ($filedata as $row) {
        if (insert_row($sql, $row)) {
            $inserted++;
        }
    }
    $pdo->commit();

    $skipped = count($filedata) - $inserted;
    echo "Insert complete: {$inserted} inserted, {$skipped} skipped.\n";
}

/**
 * Insert a single row into the users table.
 *
 * @param PDOStatement $sql The prepared insert statement.
 * @param array $row The row data containing name, surname, email.
 * @return bool True if the row was inserted, false if skipped or errored.
 */
function insert_row(PDOStatement $sql, array $row): bool {
    try {
        $sql->execute([
            ':name' => $row['name'],
            ':surname' => $row['surname'],
            ':email' => $row['email'],
        ]);
    } catch (PDOException $e) {
        echo "Error inserting '{$row['name']} {$row['surname']}': " . $e->getMessage() . "\n";
        return false;
    }

    if ($sql->rowCount() === 0) {
        echo "Skipped duplicate email: {$row['email']}\n";
        return false;
    }

    return true;
}

/**
 * Verify that the database connection options are valid.
 *
 * @param array $options The parsed options containing database connection info.
 * @return bool True if options are valid, false otherwise.
 */
function valid_db_options(array $options): bool {
    // Verify that the database connection options are provided.
    if ($options['u'] === null || $options['p'] === null || $options['h'] === null) {
        return false;
    }
    return true;
}
