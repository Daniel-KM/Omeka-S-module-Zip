Zip (module for Omeka S)
========================

[Zip] is a module for [Omeka S] that allows to create zip of resources (record +
media files) that the visitor can download, and zip of files directories for
backup purpose.


Installation
------------

See general end user documentation for [installing a module].

* From the zip

Download the last release [Zip.zip] from the list of releases (the master does
not contain the dependency), and uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Zip`, go to the root module, and run:

```sh
composer install --no-dev
```


Usage
-----

### Zip of files directories

The zip are created one-time when you check the box "create zips now" in the
main settings. Zip files are available in directory "files/zip" at the root of
Omeka.

The first zip is always `files/zip/{type}_0001.zip`, for example "files/zip/large_0001.zip".
The number total of files is indicated in the comments of each zip file. A list
of files is available at "files/zip/zipfiles.txt".


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

* Copyright Daniel Berthereau, 2017-2023 [Daniel-KM] on GitLab)

The admin part has been built for the project [Watau] and the public part has
been built for the digital library [Explore] of [Université PSL] (Paris Sciences & Lettres).


[Zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Zip
[Omeka S]: https://omeka.org/s
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Zip.zip]: https://github.com/Daniel-KM/Omeka-S-module-Zip/-/releases
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Zip/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: https://github.com/sandywalker/webui-popover/blob/master/LICENSE.txt
[Watau]: https://watau.fr
[Explore]: https://bibnum.explore.psl.eu
[Université PSL]: https://psl.eu
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
