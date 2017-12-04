Webgriffe ESB
=============

Simple, beanstalkd powered, ESB framework.

Introduction
------------

Webgriffe ESB is a PHP framework that aims to speed up the development of [Enterprise Service Buses](https://en.wikipedia.org/wiki/Enterprise_service_bus).

It uses [Beanstalkd](http://kr.github.io/beanstalkd/) as a queue engine and it's built on top of popular open-sourced libraries like:

* [Amp](http://amphp.org/)
* [Symfony's Dependency Injection](http://symfony.com/doc/current/components/dependency_injection.html)
* [Monolog](https://github.com/Seldaek/monolog)

Architecture & Core concepts
----------------------------

Integrating different systems together is a matter of data flows. With Webgriffe ESB every data flow goes, one way, from a system to another through a Beanstalkd **tube**. Every tube must have a **producer** which produces **jobs** and a **worker** which works that jobs. So data goes from the producer to the worker through the tube.

With Webgriffe ESB you integrate different systems by only implementing workers and producers. The framework will take care about the rest.

Webgriffe ESB is designed to use a single binary which is used as a main entry point of the whole application; all the producers and workers are started and executed by a single PHP binary. This is possible by using [Amp](http://amphp.org/) concurrency framework.

Installation
------------
Require this package using [Composer](https://getcomposer.org/):

```bash
composer require webgriffe/esb dev-master
```

Configuration
-------------
Copy the sample configuration file into your ESB root directory:

```bash
cp vendor/webgriffe/esb/esb.yml.sample ./esb.yml
```

The `esb.yml` file is the main configuration of your ESB application, where you have to register workers and producers. All the services implementing 
[WorkerInterface](https://github.com/webgriffe/esb/blob/master/src/WorkerInterface.php) and [ProducerInterface](https://github.com/webgriffe/esb/blob/master/src/ProducerInterface.php) are registered automatically as workers and producers. Refer to the [Symfony Dependency Injection](http://symfony.com/doc/current/components/dependency_injection.html) component documentation and the [sample configuration file](https://github.com/webgriffe/esb/blob/master/esb.yml.sample) for more information about configuration of your ESB services.

Deployment
----------
As said all workers and producers are managed by a single PHP binary. This binary is located at `vendor/bin/esb`. So to deploy and run your ESB application all you have to do is to deploy your application as any other PHP application (for example using [Deployer](https://deployer.org/)) and make sure that `vendor/bin/esb` is always running (we suggest to use [Supervisord](http://supervisord.org/) for this purpose).

Keep in mind that the `vendor/bin/esb` binary logs its operations to `stdout` and errors using `error_log()` function. With a standard PHP CLI configuration all the `error_log()` entries are then redirected to `stderr`. This is done through [Monolog](https://github.com/Seldaek/monolog)'s [StreamHandler](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/StreamHandler.php) and [ErrorHandler](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/ErrorLogHandler.php) handlers. You can also add your own handlers using the `esb.yml` configuration file.

License
-------
This library is under the MIT license. See the complete license in the LICENSE file.

Credits
-------
Developed by [Webgriffe®](http://www.webgriffe.com/).
