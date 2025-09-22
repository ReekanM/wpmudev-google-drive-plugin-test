Overview

The Posts Maintenance CLI allows you to run post scans and check scan history directly from the command line, without needing the WordPress admin panel.

Available Commands
1. Run a Scan
wp posts-maintenance scan [--post_types=<types>] [--batch_size=<number>]
--post_types
Comma-separated list of post types to scan.
Default: post,page

--batch_size
Number of posts per batch (default: 50).

Example:
wp posts-maintenance scan --post_types=post,page,attachment --batch_size=20

(Starts a new scan and shows progress until completion.)

2. List Recent Scans
wp posts-maintenance list

Shows the history of previous scans, including:

    Scan ID
    Started time
    Post types scanned
    Total posts processed

Example output:

+------------------------+---------------------+----------------------+-------+
| ID                     | Started             | Types                | Total |
+------------------------+---------------------+----------------------+-------+
| scan_1758535009_mTvVLJ | 2025-09-22 09:56:49 | post,page            | 3     |
| scan_1758534953_DacOQ7 | 2025-09-22 09:55:53 | post,page            | 3     |

WP-CLI Help

You can always view built-in help:
wp help posts-maintenance scan
wp help posts-maintenance list

Note :
If WP-CLI is not globally installed, you can run it via XAMPP PHP (Windows/XAMPP):
C:\xampp\php\php.exe wp-cli.phar posts-maintenance scan
C:\xampp\php\php.exe wp-cli.phar posts-maintenance scan --post_types=post,page
C:\xampp\php\php.exe wp-cli.phar posts-maintenance list
