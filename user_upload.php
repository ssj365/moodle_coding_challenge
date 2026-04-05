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
    php user_upload.php --file users.csv -u dbuser -p dbpass -h localhost --dry_run
";

$options = retrieve_parsed_arguments();

if ($options['help']) {
    echo $usage;
    exit(0);
}

if ($options['create_table']) {
    create_users_table($options);
    exit(0);
}

if (!empty($options['file'])) {
    process_csv_file($options);
    exit(0);
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
        echo "Error: Missing database connection options (-u, -p, -h are required).\n";
        exit(1);
    }

    $pdo = db_connect($options);
    $sql = "DROP TABLE IF EXISTS users;
            CREATE TABLE users (
                name VARCHAR(255) NOT NULL,
                surname VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE
            );";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        echo "Error: Failed to create users table: " . $e->getMessage() . "\n";
        exit(1);
    }

    echo "Users table created successfully.\n";
}

/**
 * Process the CSV file and insert valid rows into the database.
 *
 * @param array $options The parsed options containing file and database info.
 */
function process_csv_file(array $options): void {
    $filename = $options['file'];

    if (!is_file($filename) || !is_readable($filename) ||
        strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
        echo "Error: File '{$filename}' is not a readable CSV file. Check CSV file exists and has the correct permissions.\n";
        exit(1);
    }

    // Check DB options for username, password, and host.
    if (!valid_db_options($options)) {
        echo "Error: Missing database connection options (-u, -p, -h are required).\n";
        exit(1);
    }

    // Before processing, normalize the names and emails.
    $filedata = normalize_csv_data($filename);

    // Insert the data into the database, unless it's a dry run.
    if ($options['dry_run']) {
        echo "Dry run mode: No changes will be made to the database. Data to be inserted:\n";
        print_r($filedata);
        exit(0);
    }
    $dboptions = [
        'u' => $options['u'],
        'p' => $options['p'],
        'h' => $options['h'],
    ];
    add_to_database($filedata, $dboptions);
    return;
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
        echo "Error: Unable to open file '{$filename}'.\n";
        exit(1);
    }

    // Read and validate the header row.
    $header = fgetcsv($file);
    if ($header === false) {
        echo "Error: CSV file is empty or unreadable.\n";
        fclose($file);
        exit(1);
    }
    $header = array_map(fn($col) => strtolower(trim($col)), $header);

    // Find the column indexes for name, surname, and email.
    $nameheader = array_search('name', $header);
    $surnameheader = array_search('surname', $header);
    $emailheader = array_search('email', $header);

    if ($nameheader === false || $surnameheader === false || $emailheader === false) {
        echo "Error: CSV header must contain 'name', 'surname', 'email'.\n";
        fclose($file);
        exit(1);
    }

    // Read and normalize each row.
    $rows = [];
    $expectedcols = count($header);
    while (($row = fgetcsv($file)) !== false) {
        if (count($row) !== $expectedcols) {
            continue;
        }
        $name = ucwords(strtolower(trim($row[$nameheader])), " \t\r\n\f\v-'");
        $surname = ucwords(strtolower(trim($row[$surnameheader])), " \t\r\n\f\v-'");
        $email = strtolower(trim($row[$emailheader]));

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

    try {
        return new PDO($dsn, $options['u'], $options['p'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $e) {
        echo "Error: Unable to connect to PostgreSQL. Check your connection details.\n";
        exit(1);
    }
}

/**
 * Add the normalized data to the database.
 *
 * @param array $filedata The normalized data from the CSV file.
 * @param array $dboptions The database connection options.
 */
function add_to_database(array $filedata, array $dboptions): void {
    $pdo = db_connect($dboptions);

    $inserted = 0;
    $skipped = 0;

    $sql = $pdo->prepare(
        'INSERT INTO users (name, surname, email) VALUES (:name, :surname, :email) ON CONFLICT (email) DO NOTHING'
    );

    foreach ($filedata as $row) {
        try {
            $sql->execute([
                ':name' => $row['name'],
                ':surname' => $row['surname'],
                ':email' => $row['email'],
            ]);
        } catch (PDOException $e) {
            echo "Error inserting '{$row['name']} {$row['surname']}': " . $e->getMessage() . "\n";
            $skipped++;
            continue;
        }

        if ($sql->rowCount() === 0) {
            echo "Skipped duplicate email: {$row['email']}\n";
            $skipped++;
            continue;
        }
        $inserted++;
    }

    echo "Insert complete: {$inserted} inserted, {$skipped} skipped.\n";
}

/**
 * Verify that the database connection options are valid.
 *
 * @param array $options The parsed options containing database connection info.
 * @return bool True if options are valid, false otherwise.
 */
function valid_db_options(array $options): bool {
    // Verify that the database connection options are valid.
    if (empty($options['u']) || empty($options['p']) || empty($options['h'])) {
        return false;
    }
    return true;
}
