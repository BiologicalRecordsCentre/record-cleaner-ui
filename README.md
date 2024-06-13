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

