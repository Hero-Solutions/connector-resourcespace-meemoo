# Connector ResourceSpace - meemoo

## Requirements

The connector requires the following components (specific installation instructions are written for a Windows computer):

* Access to a ResourceSpace instance
* PHP >= 7.1, you can find the current latest version of PHP for Windows (7.4) [here](https://windows.php.net/downloads/releases/php-7.4.11-Win32-vc15-x64.zip), available on the [download page](https://windows.php.net/download) of php.net.
* PHP openssl and ssh2 extensions
* A MySQL or MariaDB server
* Symfony 4.4, you can download the Windows installer [here](https://getcomposer.org/Composer-Setup.exe), available on the [download page](https://getcomposer.org/download) of getcomposer.org.
* [Git Bash](https://git-scm.com/downloads), or an alternative Git command line tool for non-Windows users.

## Preparation

You need to provide a database to use with the connector, this is used to store MD5 file checksums to prevent duplicate offloads, export jobs with their status and download URL's with their expiry date.
Two tables must be present in this database, called 'file_checksums' and 'exports', according to the following schema:
```
CREATE TABLE `file_checksums` (
    `file_checksum` CHAR(32) NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY(file_checksum)
);
CREATE TABLE `exports` (
    `publisher` VARCHAR(255) NOT NULL,
	`id` VARCHAR(255) NOT NULL,
    `job_id` VARCHAR(255) NOT NULL,
	`status` TINYINT(1) NOT NULL DEFAULT 0,
	`download_url` VARCHAR(255) NULL DEFAULT NULL,
	`expires` TIMESTAMP NULL DEFAULT NULL
);
```
Note: in table exports, status '0' is pending, '1' is completed.

## Forking

You can copy this repository onto the Github profile of your own company or organization by forking it. To do so, simply click the "Fork" button at the top right of this page.

## Installation

Make sure you have a local folder where you want to set up this project (for example C:/MyOrganization/Projects/).
Start Git Bash and use the 'cd' command to navigate to the aforementioned projects folder (for example "cd C:/MyOrganization/Projects").

Clone this repository (replace "myorganization" with your organization name on Github, or "Hero-Solutions" if you want to clone directly from this project rather than a fork):
```
git clone https://github.com/myorganization/connector-resourcespace-meemoo.git
```
cd into the folder:
```
cd connector-resourcespace-meemoo
```
Install the necessary dependencies through composer
```
composer install
```

## Configuration

After installing the project and its dependencies, you need to set up the configuration of the project.
To do this, copy 'connector.yml.sample' to 'connector.yml' inside the 'config' directory.
Once you have done this, fill in the empty fields next to 'url', 'username' and 'key' under 'resourcespace_api', where you enter the URL of your ResourceSpace installation along with your ResourceSpace username and API key.
Also copy '.env.sample' to '.env' and fill in the DATABASE_URL parameter with your username, password, host and database name.

## Usage

To test the meemoo metadata model, run the following command:
```
php bin/console app:test-metadata
```

This will generate zero, one or more metadata files inside the folder 'output' based on the template that is defined in 'meemoo_metadata_template.xml' inside the 'config' folder. The amount of files depend on the amount of resources in your ResourceSpace installation that have a non-empty 'offloadStatus'.

To actually offload files and metadata (or to update metadata of existing resources in meemoo):
```
php bin/console app:offload-resources
```

To check if the last offload was successful and delete the appropriate original images:
```
php bin/console app:process-offloaded-resources
```

