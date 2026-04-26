# Menumbing Async Queue

An enhanced async queue component for the [Hyperf](https://hyperf.io) framework. Supports multiple drivers (Redis, AMQP, Sync) with failed message tracking, retry strategies, and monitoring capabilities.

## Installation

```bash
composer require menumbing/async-queue
```

### Optional Dependencies

| Package | Purpose |
|---|---|
| `hyperf/amqp` | Required to use the AMQP driver |
| `hyperf/database` | Required to use the database failed recorder |
| `hyperf/di` | Required to use annotations |

Publish the configuration file:

```bash
php bin/hyperf.php vendor:publish menumbing/async-queue
```

This publishes `config/autoload/async_queue.php` and the failed messages migration.

## Configuration

### Basic Configuration

```php
// config/autoload/async_queue.php
return [
    'pools' => [
        'default' => [
            'driver' => \Hyperf\AsyncQueue\Driver\RedisDriver::class,
            'auto_register_process' => true,
            'redis' => [
                'pool' => 'default',
            ],
            'channel' => '{queue}',
            'timeout' => 2,
            'retry_seconds' => 5,
            'handle_timeout' => 10,
            'processes' => 1,
            'concurrent' => [
                'limit' => 10,
            ],
            'max_messages' => 0,
        ],
    ],

    'failed' => [
        'recorder' => \Hyperf\AsyncQueue\Failed\RedisFailedQueueRecorder::class,
        'options' => [
            'pool' => 'default',
            'group' => env('APP_NAME', 'hyperf'),
        ],
    ],

    'debug' => [
        'before' => true,
        'after' => true,
        'failed' => true,
        'retry' => true,
    ],
];
```

### Common Options

| Option | Type | Default | Description |
|---|---|---|---|
| `driver` | `string` | `RedisDriver::class` | The queue driver class |
| `auto_register_process` | `bool` | `true` | Auto-register the consumer process |
| `channel` | `string` | `'queue'` | Channel name used as prefix for queue keys/exchanges |
| `retry_seconds` | `int\|array` | `5` | Delay in seconds before retrying a failed message. Use an array for progressive delays (e.g., `[1, 5, 10]`) |
| `handle_timeout` | `int` | `10` | Maximum seconds to handle a single message |
| `processes` | `int` | `1` | Number of consumer processes |
| `concurrent.limit` | `int\|null` | `null` | Max concurrent message processing. `null` means no limit |
| `max_messages` | `int` | `0` | Max messages to consume before process exits. `0` means unlimited |
| `pool` | `string` | (required) | The pool name, set automatically by the driver factory |

### Redis Driver Options

| Option | Type | Default | Description |
|---|---|---|---|
| `redis.pool` | `string` | `'default'` | The Redis connection pool name |
| `timeout` | `int` | `2` | Blocking pop timeout in seconds |

### AMQP Driver Configuration

To use the AMQP driver, install the required package:

```bash
composer require hyperf/amqp
```

Then configure a pool with the AMQP driver:

```php
'pools' => [
    'default' => [
        'driver' => \Hyperf\AsyncQueue\Driver\Amqp\AmqpDriverAdapter::class,
        'auto_register_process' => true,
        'channel' => 'queue', // Exchange name
        'retry_seconds' => [1, 5, 10, 30],
        'handle_timeout' => 10,
        'processes' => 1,
        'concurrent' => [
            'limit' => 10,
        ],
        'max_messages' => 0,
        'amqp' => [
            // AMQP connection pool name (matches hyperf/amqp config)
            'pool' => 'default',

            // Exchange type: Type::DIRECT, Type::TOPIC, Type::FANOUT
            'exchange_type' => \Hyperf\Amqp\Message\Type::DIRECT,

            // Route failed messages to a separate exchange/queue
            'reroute_failed' => false,

            // Use the rabbitmq-delayed-message-exchange plugin for delays
            // Set to false to use TTL + Dead Letter Queue approach instead
            'use_delayed_exchange' => true,

            // QoS prefetch count — automatically set to concurrent.limit (default: 1)
            // This ensures RabbitMQ only delivers as many messages as the consumer can handle concurrently
            // 'prefetch_count' is not configurable; set concurrent.limit instead

            // Queue declaration options
            'queue_durable' => true,
            
            // Queue auto deletion options
            'queue_auto_delete' => false,

            // Exchange auto deletion options
            'exchange_auto_delete' => false,

            // Additional queue arguments (e.g., x-max-length, x-message-ttl)
            'queue_arguments' => [],
            
            // Queue name
            'queue' => 'payment.completed.notification-service',
            
            // Routing key for message routing
            'routing_key' => 'payment.completed',

            // --- Optional overrides (see "Exchange and Queue Naming" below) ---
            // 'delay_exchange' => 'payment.delayed',
            // 'delay_queue' => 'payment.completed.notification-service.delay',
            // 'failed_exchange' => 'payment.failed',
            // 'failed_queue' => 'payment.completed.notification-service.failed',
            // 'failed_routing_key' => 'default',
        ],
    ],
],
```

#### AMQP Options Reference

| Option | Type | Default | Description |
|---|---|---|---|
| `amqp.pool` | `string` | `'default'` | AMQP connection pool name |
| `amqp.queue` | `string\|null` | `null` | Queue name. When `null`, uses `{exchange}.{app_name}` |
| `amqp.routing_key` | `string\|null` | `null` | Routing key for message routing. When `null`, uses `{exchange}` |
| `amqp.exchange_type` | `Type` | `Type::DIRECT` | Exchange type: `DIRECT`, `TOPIC`, or `FANOUT` |
| `amqp.reroute_failed` | `bool` | `false` | Route failed messages to a dedicated failed exchange/queue |
| `amqp.use_delayed_exchange` | `bool` | `true` | Use `rabbitmq-delayed-message-exchange` plugin. Set `false` for TTL+DLQ approach |
| `amqp.queue_durable` | `bool` | `true` | Whether the queue survives broker restart |
| `amqp.queue_auto_delete` | `bool` | `false` | Whether the queue is deleted when the last consumer disconnects |
| `amqp.exchange_auto_delete` | `bool` | `false` | Whether exchanges are deleted when all bound queues are removed |
| `amqp.queue_arguments` | `array` | `[]` | Additional AMQP queue arguments |
| `amqp.delay_exchange` | `string\|null` | `null` | Delay exchange name. When `null`, uses `{channel}.delayed` |
| `amqp.delay_queue` | `string\|null` | `null` | Delay queue name. When `null`, uses `{queue}.delay` |
| `amqp.failed_exchange` | `string\|null` | `null` | Failed exchange name. When `null`, uses `{channel}.failed` |
| `amqp.failed_queue` | `string\|null` | `null` | Failed queue name. When `null`, uses `{queue}.failed` |
| `amqp.failed_routing_key` | `string\|null` | `null` | Failed routing key. When `null`, uses the pool name |

### Failed Message Recorders

Two built-in recorders are available:

**Redis Recorder** (default):
```php
'failed' => [
    'recorder' => \Hyperf\AsyncQueue\Failed\RedisFailedQueueRecorder::class,
    'options' => [
        'pool' => 'default',
        'group' => env('APP_NAME', 'hyperf'),
    ],
],
```

**Database Recorder** (requires `hyperf/database`):
```php
'failed' => [
    'recorder' => \Hyperf\AsyncQueue\Failed\DatabaseFailedQueueRecorder::class,
],
```

Make sure to run the migration:
```bash
php bin/hyperf.php migrate
```

### Debug Logging

Enable debug logging per event type:

```php
'debug' => [
    'before' => true,   // Log before message handling
    'after' => true,    // Log after successful handling
    'failed' => true,   // Log failed messages
    'retry' => true,    // Log message retries
],
```

## Creating Jobs

Extend the `Job` class and implement the `handle()` method:

```php
<?php

namespace App\Job;

use Hyperf\AsyncQueue\Job;

class SendEmailJob extends Job
{
    protected int $maxAttempts = 3;

    public function __construct(
        protected string $email,
        protected string $subject,
        protected string $body,
    ) {
    }

    public function handle(): void
    {
        // Send the email...
    }

    public function fail(\Throwable $e): void
    {
        // Optional: handle failure (called when all attempts are exhausted)
    }
}
```

### Job Properties

| Property | Type | Default | Description |
|---|---|---|---|
| `$maxAttempts` | `int` | `0` | Maximum retry attempts. `0` means no retry |

### Job Context

Jobs support context propagation for passing metadata:

```php
$job = new SendEmailJob('user@example.com', 'Hello', 'World');
$job->withContext(['trace_id' => '...', 'user_id' => 123]);
```

## Dispatching Jobs

### Using the Helper Function

```php
use function Hyperf\AsyncQueue\dispatch;

// Dispatch immediately
dispatch(new SendEmailJob('user@example.com', 'Hello', 'World'));

// Dispatch with a delay (in seconds)
dispatch(new SendEmailJob('user@example.com', 'Hello', 'World'), delay: 60);

// Dispatch with max attempts
dispatch(new SendEmailJob('user@example.com', 'Hello', 'World'), maxAttempts: 3);

// Dispatch to a specific pool
dispatch(new SendEmailJob('user@example.com', 'Hello', 'World'), pool: 'emails');
```

### Using the Driver Directly

```php
use Hyperf\AsyncQueue\Driver\DriverFactoryInterface;

$factory = $container->get(DriverFactoryInterface::class);
$driver = $factory->get('default');

$driver->push(new SendEmailJob('user@example.com', 'Hello', 'World'), delay: 0);
```

### Using Annotations

Apply the `#[AsyncQueueMessage]` attribute to any class method to automatically dispatch it as a job:

```php
use Hyperf\AsyncQueue\Annotation\AsyncQueueMessage;

class EmailService
{
    #[AsyncQueueMessage(pool: 'default', delay: 0, maxAttempts: 3)]
    public function sendWelcomeEmail(string $email): void
    {
        // This method will be dispatched as an async job
    }
}
```

> Requires `hyperf/di` package.

## Events

The queue system dispatches the following events:

| Event | Description |
|---|---|
| `BeforeHandle` | Dispatched before a message is handled |
| `AfterHandle` | Dispatched after a message is successfully handled |
| `FailedHandle` | Dispatched when a message fails after all retries are exhausted |
| `RetryHandle` | Dispatched when a message is retried |
| `QueueLength` | Dispatched periodically with queue length information |

### Listening to Events

```php
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\Event\Contract\ListenerInterface;

class FailedJobListener implements ListenerInterface
{
    public function listen(): array
    {
        return [FailedHandle::class];
    }

    public function process(object $event): void
    {
        /** @var FailedHandle $event */
        $job = $event->getMessage()->job();
        $exception = $event->getThrowable();
        $pool = $event->getPool();

        // Send alert, log to external service, etc.
    }
}
```

## Retry Strategy

Configure `retry_seconds` to control retry delay:

```php
// Fixed delay: always retry after 5 seconds
'retry_seconds' => 5,

// Progressive delay: 1s, 5s, 10s, 30s (last value repeats for subsequent attempts)
'retry_seconds' => [1, 5, 10, 30],
```

Set `maxAttempts` on your job to control the number of retries:

```php
class MyJob extends Job
{
    protected int $maxAttempts = 5; // Retry up to 5 times
}
```

When `maxAttempts` is `0` (default), the job will not be retried and will be recorded as failed immediately.

## Commands

### Queue Info

Display queue statistics:

```bash
php bin/hyperf.php queue:info [pool]
```

For the AMQP driver, this returns `waiting` (messages in queue), `consumers` (active consumer count), and `failed` (failed message count). If `reroute_failed` is enabled, it also returns `failed_queue` (messages in the RabbitMQ failed queue).

### Reload Failed Messages

Reload a single failed message by ID:

```bash
php bin/hyperf.php queue:reload <id>
```

Reload all failed messages back into the waiting queue:

```bash
php bin/hyperf.php queue:reload-all [--pool=default]
```

### Flush Failed Messages

Delete all failed messages:

```bash
php bin/hyperf.php queue:flush [--pool=default]
```

For the AMQP driver with `reroute_failed` enabled, this also purges the failed queue in RabbitMQ.

## AMQP Advanced Topics

### Delayed Messages

The AMQP driver supports two approaches for delayed message delivery:

#### 1. Delayed Message Exchange Plugin (default)

This is the default behavior (`use_delayed_exchange = true`). It requires the [rabbitmq-delayed-message-exchange](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange) plugin.

**How it works:** Messages are published with an `x-delay` header to a `x-delayed-message` type exchange. The plugin holds the message and delivers it after the delay expires.

**Pros:**
- Accurate per-message delay timing
- Messages are delivered in correct delay order

**Cons:**
- Requires installing a RabbitMQ plugin

To install the plugin:
```bash
rabbitmq-plugins enable rabbitmq_delayed_message_exchange
```

#### 2. TTL + Dead Letter Queue (no plugin required)

Set `use_delayed_exchange` to `false` to use a TTL-based approach that requires no additional plugins.

```php
'amqp' => [
    'use_delayed_exchange' => false,
    // ...
],
```

**How it works:** Delayed messages are published to a dedicated delay exchange with a per-message TTL (`expiration` property). When the TTL expires, the message is routed to the main exchange via a dead-letter exchange (DLX) configuration.

**Pros:**
- No plugin installation required
- Works with any standard RabbitMQ installation

**Cons:**
- RabbitMQ only checks expiration of the message at the head of the queue. If a message with a longer TTL is ahead of one with a shorter TTL, the shorter one won't be delivered until the longer one expires or is consumed. This is a known RabbitMQ limitation with per-message TTL.

> **Note:** When `use_delayed_exchange` is `true` (default) and you never dispatch jobs with a delay (`delay = 0`), the `x-delayed-message` exchange type is still declared but no `x-delay` header is set on messages. If you want to avoid requiring the plugin entirely, set `use_delayed_exchange` to `false`.

### Failed Message Routing

When `reroute_failed` is enabled, messages that fail after all retry attempts are routed to a dedicated failed exchange and queue using RabbitMQ's dead-letter exchange mechanism.

```php
'amqp' => [
    'reroute_failed' => true,
],
```

This creates:
- Exchange: `{channel}.failed`
- Queue: `{queue_name}.failed`

The main queue is configured with `x-dead-letter-exchange` and `x-dead-letter-routing-key` arguments pointing to the failed exchange.

Failed messages are always recorded via the configured `FailedQueueRecorderInterface` regardless of this setting. The `reroute_failed` option provides an additional copy of the raw message in RabbitMQ for inspection or manual reprocessing.

### QoS / Prefetch Count

The AMQP driver automatically sets `prefetch_count` equal to `concurrent.limit` (default: `1`). This ensures RabbitMQ only delivers as many unacknowledged messages as the consumer can process concurrently.

```php
'concurrent' => [
    'limit' => 10, // prefetch_count will also be 10
],
```

There is no separate `amqp.prefetch_count` configuration — it is always derived from `concurrent.limit` to keep the two values in sync.

### Queue and Exchange Declaration Options

Customize how queues and exchanges are declared in RabbitMQ:

```php
'amqp' => [
    'queue_durable' => true,          // Queue survives broker restart
    'queue_auto_delete' => false,     // Queue persists after last consumer disconnects
    'exchange_auto_delete' => false,  // Exchange persists after all bound queues are removed
    'queue_arguments' => [
        'x-max-length' => 100000,          // Max messages in queue
        'x-message-ttl' => 86400000,       // Message TTL in ms (24 hours)
        'x-queue-type' => 'quorum',        // Use quorum queues for HA
    ],
],
```

When `queue_auto_delete` is `true`, queues will be automatically deleted by RabbitMQ when the **last consumer disconnects** (e.g., when the application shuts down or all consumer processes stop). Any messages remaining in the queue will be lost. This is useful for temporary queues in development or for transient consumers that don't need to persist messages between restarts. Default is `false`.

When `exchange_auto_delete` is `true`, all exchanges created by the driver (main, delay, and failed) will be automatically deleted by RabbitMQ when **all queues bound to them are removed**. This is useful for ephemeral or development setups where you don't want exchanges to accumulate. Default is `false`.

> **Note:** Changing `exchange_auto_delete` (or `queue_auto_delete`) on an existing exchange/queue has no effect. RabbitMQ does not allow changing properties of already-declared resources. You must delete the existing exchange/queue manually from the RabbitMQ Management UI first, then restart the application to re-declare them with the new settings.

### Exchange and Queue Naming

The AMQP driver uses dot (`.`) separators for naming. The `channel` config determines the exchange name, and all other names are derived using dot-separated conventions:

| Resource | Fallback Pattern | Example (`channel = payment`) |
|---|---|---|
| Main exchange | `{channel}` | `payment` |
| Delay exchange | `{channel}.delayed` | `payment.delayed` |
| Failed exchange | `{channel}.failed` | `payment.failed` |
| Main queue | `{exchange}.{app_name}` | `payment.myapp` |
| Routing key | `{exchange}` | `payment` |
| Delay queue | `{queue}.delay` | `payment.myapp.delay` |
| Failed queue | `{queue}.failed` | `payment.myapp.failed` |

All names can be overridden individually via the `amqp.*` config keys (see AMQP Options Reference).

#### Naming Convention Best Practices

When working with multiple services and domains, we recommend using a consistent naming pattern:

- **Exchange** = domain name (e.g., `payment`, `order`, `notification`)
- **Queue** = `{domain}.{action}.{consumer}` (e.g., `payment.completed.notification-service`)
- **Routing Key** = `{domain}.{action}` (e.g., `payment.completed`)

This pattern allows multiple consumers to each have their own queue bound to the same exchange and routing key. Each consumer receives a copy of the message independently.

**Example: Payment Service (producer)**

```php
'pools' => [
    'default' => [
        'driver' => AmqpDriverAdapter::class,
        'channel' => 'payment',
        'amqp' => [
            'queue' => 'payment.completed.payment-service',
            'routing_key' => 'payment.completed',
        ],
        // ...
    ],
],
```

**Example: Notification Service (consumer)**

```php
'pools' => [
    'payment' => [
        'driver' => AmqpDriverAdapter::class,
        'channel' => 'payment',
        'amqp' => [
            'queue' => 'payment.completed.notification-service',
            'routing_key' => 'payment.completed',
        ],
        // ...
    ],
    'order' => [
        'driver' => AmqpDriverAdapter::class,
        'channel' => 'order',
        'amqp' => [
            'queue' => 'order.process.notification-service',
            'routing_key' => 'order.process',
        ],
        // ...
    ],
],
```

**Example: Accounting Service (consumer)**

```php
'pools' => [
    'payment' => [
        'driver' => AmqpDriverAdapter::class,
        'channel' => 'payment',
        'amqp' => [
            'queue' => 'payment.completed.accounting-service',
            'routing_key' => 'payment.completed',
        ],
        // ...
    ],
],
```

In this setup, when Payment Service publishes a message to the `payment` exchange with routing key `payment.completed`, both `payment.completed.notification-service` and `payment.completed.accounting-service` queues receive a copy of the message.

Dispatch to a specific pool:

```php
dispatch(new SendEmailJob(...), pool: 'payment');
dispatch(new PushNotificationJob(...), pool: 'order');
```

#### Message Metadata

When producing messages, the AMQP driver automatically embeds metadata in two ways:

**AMQP Headers** — set as application headers on every published message:

| Header | Description |
|---|---|
| `x-message-name` | Fully-qualified job class name (e.g. `App\Job\SendEmailJob`) |
| `x-message-id` | Unique message ID (UUIDv7) |

These headers are visible in the RabbitMQ Management UI and can be used by any monitoring tool or consumer in any language.

**Body Metadata** — exchange, routing key, and queue are embedded in the message body:

- The DLQ reporter uses this to record accurate queue/exchange information for failed jobs
- The dashboard uses this to display routing details and enable correct retry routing

The metadata is transparently wrapped and unwrapped during serialization/deserialization. Old messages without metadata remain fully compatible.

## Drivers

### Redis Driver

The default driver. Uses Redis lists and sorted sets for queue management.

- Waiting queue: Redis list (`LPUSH`/`BRPOP`)
- Delayed queue: Redis sorted set (scored by timestamp)
- Failed messages: Recorded via `FailedQueueRecorderInterface`

### AMQP Driver

Uses RabbitMQ (or any AMQP 0-9-1 compatible broker) for message delivery.

- Push-based consumption (broker pushes messages to consumer)
- Exchange/queue declaration is handled automatically
- Supports delayed messages via plugin or TTL+DLQ
- Supports failed message routing to a dedicated queue

### Sync Driver

Executes jobs synchronously in the current process. Useful for testing or development:

```php
'pools' => [
    'default' => [
        'driver' => \Hyperf\AsyncQueue\Driver\SyncDriver::class,
    ],
],
```

## License

MIT
