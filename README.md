Record Cleaner
==============

A Drupal module providing a user interface to the record cleaner service.

Configuration
-------------

After installing the module,

 - enable it in `/admin/modules`,
 - enter your connection details to the record cleaner service at
 `/admin/config/services/record_cleaner`,
 - grant the `Use record cleaner` permission to relevant roles.

Use
---

Visit the `/record_cleaner` page.

Development
-----------

A DDEV configuration is provided for local development.

If not already present
[install DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/).

Git clone the module from github.

At a command prompt, change to the folder where you just cloned the module and
run `ddev start`.

Run `ddev poser`.

Run `ddev symlink-project`.

For information on step debugging, see the
[configuration instructions](https://ddev.readthedocs.io/en/latest/users/debugging-profiling/step-debugging/).

With a browser, navigate to the url given by DDEV, probably
https://record-cleaner.ddev.site and complete the normal Drupal installation

Follow the configuration instructions above to enable and configure the module.

You can run up a local copy of the
[record cleaner service](https://github.com/BiologicalRecordsCentre/record-cleaner-service)
and interact with that if you want. For the docker containers to communicate
you need to add them to the same network as follows:

`docker network create rc-bridge`
`docker network connect rc-bridge <service-container-name>`
`docker network connect rc-bridge <drupal-container-name>`

Currently the service container is calling itself `recordcleanerservice-dev`
and the drupal container is `ddev-record-cleaner-web`.

The base url you need to set in the module is then
`http://recordcleanerservice-dev:8000/`
