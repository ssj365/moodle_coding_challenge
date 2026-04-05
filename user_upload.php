#!/usr/bin/php
<?php
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
    echo "Creating or rebuilding the users table in the database...\n";
}

/**
 * Process the CSV file and insert valid rows into the database.
 *
 * @param array $options The parsed options containing file and database info.
 */
function process_csv_file(array $options): void {
    $filename = $options['file'];

    if (!is_file($filename) || !is_readable($filename) || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
        echo "Error: File '{$filename}' is not a readable CSV file. Check CSV file exists and has the correct permissions.\n";
        exit(1);
    }

    //Check DB options for username, password, and host.
    if (!valid_db_options($options) || !check_db_exists()) {
        echo "Error: Missing or incorrect database connection information. Check that the database exists and the connection information provided is correct.\n";
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
    $header = array_map(fn($col) => strtolower(trim($col)), $header);
    if ($header !== ['name', 'surname', 'email']) {
        echo "Error: CSV header must contain 'name', 'surname', 'email'.\n";
        fclose($file);
        exit(1);
    }

    // Read and normalize each row.
    $rows = [];
    while (($row = fgetcsv($file)) !== false) {
        if (count($row) !== 3) {
            continue;
        }
        $name = ucwords(strtolower(trim($row[0])));
        $surname = ucwords(strtolower(trim($row[1])));
        $email = strtolower(trim($row[2]));

        // Validate the email address.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "Error: Invalid email '{$email}' for {$name} {$surname} — skipping.\n";
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
 * Check if the database exists and is accessible.
 *
 * @return bool True if the database exists, false otherwise.
 */
function check_db_exists(): bool {
    // Check if the database exists and is accessible.
    return true;
}

/**
 * Add the normalized data to the database.
 *
 * @param array $filedata The normalized data from the CSV file.
 * @param array $dboptions The database connection options.
 */
function add_to_database(array $filedata, array $dboptions): void {
    // Add the normalized data to the database using the provided connection options.
    echo "Inserting data into the database...\n";
}

/**
 * Verify that the database connection options are valid.
 *
 * @param array $options The parsed options containing database connection info.
 */
function valid_db_options(array $options): bool {
    // Verify that the database connection options are valid.
    if (empty($options['u']) || empty($options['p']) || empty($options['h'])) {
        return false;
    }
    return true;
}
