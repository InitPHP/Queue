# InitPHP Queue

This library offers performance and asynchrony by queuing your jobs to be done later.

```
composer require initphp/queue
```

## Create Job

First, start by creating the business class. You can find a simple example below.

```php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor/autoload.php';

namespace App\Jobs;

use InitPHP\Queue\Job;

class MailJob extends Job
{
    protected string $channel = 'mailChannel';
    
    protected string $queue = 'mailQueue';
    
    public function handle(): bool
    {
        $payload = $this->getPayload();
        try {
            if (mail($payload['to'], $payload['subject'])) {
                return true;
            } else {
                return false;;
            }
        } catch (\Throwable $e) {
            return false;
        }
    }
}
```

Use the `push()` method to add jobs to the queue;

```php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor/autoload.php';
$adapter = new \InitPHP\Queue\Adapters\RabbitMQAdapter('127.0.0.1', 5267, 'guest', 'guest');

$job = new App\Jobs\MailJob($adapter);

// Add Queue Job
$job->push([
    'to'        => 'to@example.com',
    'subject'   => 'Subject Mail',
]);
```

Write your code to handle the jobs in the queue.

`consumer.php`

```php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor/autoload.php';
$adapter = new \InitPHP\Queue\Adapters\RabbitMQAdapter('127.0.0.1', 5267, 'guest', 'guest');

$adapter->handle('mailChannel', 'mailQueue');

$adapter->close();
```

Trigger your consumer code.

```
php consumer.php
```


# Adapters
- [x] [PDO (Database) Adapter](#pdo-adapter)
- [x] [RabbitMQ Adapter](#rabbitmq-adapter)
- [ ] Kafka Adapter

## PDO Adapter

- [x] PDO Extension

To initialize the PDO adapter, you need a PDO object and 2 tables.

```php
$pdo = new PDO('mysql:host=localhost;port=3307;dbname=queue_db', 'root', 'root');
$adapter = new \InitPHP\Queue\Adapters\PDOAdapter($pdo, 'queue');
```

The first of these tables is used for those waiting in line and the other for jobs that have errors. The table name into which the failed jobs fall is obtained by adding "`_failed`" as a suffix to the main table name. Accordingly, create your queue tables using the following SQL.

```sql
CREATE TABLE `queue` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `channel` VARCHAR(255) NOT NULL,
        `queue` VARCHAR(255) NOT NULL,
        `payload` TEXT NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL,
        `status` TINYINT(1) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX `channel_queue` ON `queue` (`channel`, `queue`);

CREATE TABLE `queue_failed` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `queue_id` BIGINT UNSIGNED NOT NULL,
        `channel` VARCHAR(255) NOT NULL,
        `queue` VARCHAR(255) NOT NULL,
        `payload` TEXT NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL,
        `status` TINYINT(1) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## RabbitMQ Adapter

- [x] RabbitMQ Server
- [x] "`php-amqplib/php-amqplib`" Library

```
composer require php-amqplib/php-amqplib
```

```php
$adapter = new \InitPHP\Queue\Adapters\RabbitMQAdapter('127.0.0.1', 5267, 'guest', 'guest');
```

# Getting Involved

> All contributions to this project will be published under the MIT License. By submitting a pull request or filing a bug, issue, or feature request, you are agreeing to comply with this waiver of copyright interest.

There are two primary ways to help:

- Using the issue tracker, and
- Changing the code-base.

## Using the issue tracker

Use the issue tracker to suggest feature requests, report bugs, and ask questions. This is also a great way to connect with the developers of the project as well as others who are interested in this solution.

Use the issue tracker to find ways to contribute. Find a bug or a feature, mention in the issue that you will take on that effort, then follow the Changing the code-base guidance below.

## Changing the code-base

Generally speaking, you should fork this repository, make changes in your own fork, and then submit a pull request. All new code should have associated unit tests that validate implemented features and the presence or lack of defects. Additionally, the code should follow any stylistic and architectural guidelines prescribed by the project. In the absence of such guidelines, mimic the styles and patterns in the existing code-base.

# Credits

- [Muhammet ŞAFAK](https://www.muhammetsafak.com.tr) <<info@muhammetsafak.com.tr>>

# License

Copyright &copy; 2022 [MIT License](./LICENSE) 
