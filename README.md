Webgriffe ESB
=============

Simple, beanstalkd powered, ESB framework.

[![Build Status](https://travis-ci.org/webgriffe/esb.svg?branch=master)](https://travis-ci.org/webgriffe/esb)

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

You also have to define some parameters under the `parameters` section, refer to the `esb.yml.sample` file for more informations about required parameters. Usually it's better to isolate parameters in a `parameters.yml` file which can be included in the `esb.yml` as follows:

```yaml
# esb.yml
imports:
  - { resource: parameters.yml}

services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true
  
  My\Esb\Namespace\:
    resource: 'src/*'
```

```yaml
# parameters.yml
parameters:
  beanstalkd: tcp://127.0.0.1:11300
  # Other parameters here ...
```

Deployment
----------
As said all workers and producers are managed by a single PHP binary. This binary is located at `vendor/bin/esb`. So to deploy and run your ESB application all you have to do is to deploy your application as any other PHP application (for example using [Deployer](https://deployer.org/)) and make sure that `vendor/bin/esb` is always running (we suggest to use [Supervisord](http://supervisord.org/) for this purpose).

Keep in mind that the `vendor/bin/esb` binary logs its operations to `stdout` and errors using `error_log()` function. With a standard PHP CLI configuration all the `error_log()` entries are then redirected to `stderr`. This is done through [Monolog](https://github.com/Seldaek/monolog)'s [StreamHandler](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/StreamHandler.php) and [ErrorHandler](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/ErrorLogHandler.php) handlers. Moreover all critical events are handled by the [NativeMailHander](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/NativeMailerHandler.php) (configured with `critical_events_to ` and `critical_events_from` parameters).

You can also add your own handlers using the `esb.yml` configuration file.

Contributing
------------

To contribute simply fork this repository, do your changes and then propose a pull requests. The test suite requires a running instance of Beanstalkd:

```bash
beanstalkd &
vendor/bin/phpunit
```

By default it tries to connect to a Beanstalkd running on `127.0.0.1` and default port `11300`. If you have Beanstalkd running elsewhere (for example in a Docker container) you can set the `BEANSTALKD_CONNECTION_URI` environment variable with the connection string (like `tcp://docker:11300`).

License
-------
This library is under the MIT license. See the complete license in the LICENSE file.

Credits
-------
Developed by [Webgriffe®](http://www.webgriffe.com/).
