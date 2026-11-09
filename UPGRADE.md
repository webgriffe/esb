Upgrade guide
=============

Upgrade from 2.x to 3.0
-----------------------

### Requirements

* PHP 8.1, 8.2, 8.3, 8.4 or 8.5.
* Beanstalkd and Elasticsearch 7.* as before.

### Dependencies

Several dependencies moved to a new major version. If your ESB application requires any of these packages directly, or uses their APIs, make sure your code and your own constraints are compatible:

| Package                                   | 2.x       | 3.0                |
|-------------------------------------------|-----------|--------------------|
| `symfony/dependency-injection`            | ^4.3      | ^6.4               |
| `symfony/config`                          | ^4.3      | ^6.4               |
| `symfony/serializer`                      | ^4.3      | ^6.4               |
| `symfony/property-access`                 | ^4.3      | ^6.4               |
| `symfony/property-info`                   | ^4.3      | ^6.4               |
| `symfony/yaml`                            | ^4.3      | ^5.4               |
| `symfony/deprecation-contracts`           | ^2.1      | ^3.0               |
| `monolog/monolog`                         | ^1.23     | ^3.0               |
| `psr/log`                                 | ^1.1      | ^2.0 \|\| ^3.0     |
| `ramsey/uuid`                             | ^3.8      | ^4.7               |
| `dragonmantank/cron-expression`           | ^2.0      | ^3.3               |
| `pagerfanta/pagerfanta`                   | ^2.4      | ^4.0               |
| `doctrine/annotations`                    | ^1.8      | removed            |

The framework still runs on [Amp](https://amphp.org/) version 2: producers and workers keep returning Amp v2 iterators and promises, and any Amp library you use must be compatible with Amp v2.

### Configuration (`esb.yml`)

* The `release_delay` worker option, deprecated in 2.x, has been removed. Use `error_retry_delay` instead:

  ```yaml
  flows:
    my_flow:
      worker:
        error_retry_delay: 10   # was: release_delay: 10
  ```

* Services you fetch directly from the container (for example with `$kernel->getContainer()->get(...)`) must be declared `public: true`. Symfony 6 no longer lets you fetch private services from the compiled container.

* The `annotation_reader` service has been removed, and `serializer.class_metadata_factory.annotation_loader` has been replaced by `serializer.class_metadata_factory.attribute_loader`. Update any reference to them in your configuration.

* Symfony YAML and Dependency Injection features that were deprecated in Symfony 4.x and 5.x have been removed. If your configuration triggered deprecation notices with ESB 2.x, fix them before upgrading.

### Logging

* Custom Monolog handlers, formatters and processors registered in your configuration must be compatible with Monolog 3: log records are `Monolog\LogRecord` objects instead of arrays, and levels are `Monolog\Level` enum cases.
* Prefer level names (for example `'debug'` or `'warning'`) over the `Monolog\Logger::*` constants when you pass a level to a handler: the constants still work but are deprecated in Monolog 3.
* Your own PSR-3 logger implementations, if any, must implement the `psr/log` 2 or 3 interface, which has typed method signatures.

### API changes

These changes only affect you if your code implements, extends or calls the framework classes listed below.

* The queue management has been split into a queue backend and a batch manager:
  * `Webgriffe\Esb\Service\QueueManager` has been replaced by `Webgriffe\Esb\Service\BeanstalkElasticsearchQueueBackend` (which stores and queues jobs) and `Webgriffe\Esb\Service\BatchManager` (which batches the produced jobs, created through `Webgriffe\Esb\Service\BatchManagerFactory`).
  * `Webgriffe\Esb\Service\ProducerQueueManagerInterface` has been replaced by `Webgriffe\Esb\Service\BatchManagerInterface`.
  * `Webgriffe\Esb\Service\WorkerQueueManagerInterface` has been replaced by `Webgriffe\Esb\Service\QueueBackendInterface`, which adds the `jobExists()` and `enqueueJobs()` methods.
* `ProducerInstanceInterface::produceAndQueueJobs()` now returns a promise that resolves to the number of queued jobs instead of `null`.
* `ProducerInterface::produce()` and `ProducerInstanceInterface::produceAndQueueJobs()` declare the `$data` parameter as `mixed`. Existing implementations without a parameter type keep working.
* `DateTimeBuilderInterface::build()` declares the `$time` parameter as `string`.
* The parameter of `JobInterface::addEvent()` has been renamed from `$tag` to `$event`. This only matters if you call it with named arguments.
* `Webgriffe\Esb\Model\FlowConfig::getWorkerReleaseDelay()` has been renamed to `getWorkerErrorRetryDelay()`.
* The web console pager now implements the Pagerfanta 4 interfaces:
  * The exception classes in the `Webgriffe\Esb\Console\Pager` namespace (`NotValidCurrentPageException`, `OutOfRangeCurrentPageException` and the others) have been removed. Use the ones in the `Pagerfanta\Exception` namespace instead.
  * `AsyncPager` no longer accepts the deprecated boolean arguments of `setCurrentPage()`, and `getAdapter()` returns a Pagerfanta adapter holding the already loaded results instead of the asynchronous adapter.
* The serializer mapping of the job events uses PHP attributes instead of Doctrine annotations.
