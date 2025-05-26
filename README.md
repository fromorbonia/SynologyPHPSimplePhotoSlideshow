# Simple Random Photo Slideshow

Simple, PHP only, slideshow designed to allow older iPads with unsupported iOS to be turned into picture frames. This project is focused on a Synology deployment, but any server with PHP and photos can be used.

This came out with frustration that the colour and display quality of an iPad just beats almost all the digital photo frames. Alongside many digital frames needing a memory card installing. I have holder iPads that still work, but there are no apps available on the older iOS. The apps available also tend not to have the options I want, e.g. just changing the length of time each photo is displayed.


### Configuration

This is a simple slideshow programme that is folder centric, so if you use folders to store and group your photos this is ideal.
It scans a directory for images and displays them in a random order.

The scanned directory can contain images or subdirectories containing images.

The slideshow is configured using variables at the top of the slideshow.php file:

* ``$interval`` - this sets the number of seconds a photo is displayed before the next photo is displayed.
* ``$photoDir`` - this is the path where photos or directories of photos are stored. This must be relative to the slideshow.php file.
* ``$photoExt`` - the file extension that will be search for. Only one extension is supported but it is not case sensitive.
* ``$rescanAfter`` - this is the number of minutes after which $photoDir will be rescanned. If set to zero it will just see if the config file modified date has changed
* ``$backgroundColor`` & ``$textColor`` - the background and text colors.

### Todo list
Lots of ways to extend this, key ideas:

* Add Country / Town to each photo title
* Sort by Geography - by Country, by County, by Town
* Pickup where you left off, logging how far through the playlist
* Add a configuration UI page
* Add pause / next / previous buttons on page
* Add synology user login 
* Sort out instructions for https
* Use the Synology Photo apps collections to be the source photo list
* Clever caching to reduce Synology up time and save power
* iOS hybrid app that can prevent screen locking

### Install
To install you do need to carry out a few tasks that will vary depending on the version of Synology DSM you use. Basic steps are:
* Install Web Station - [Synology KB on how to install PHP](https://kb.synology.com/en-me/DSM/tutorial/How_to_host_a_website_on_Synology_NAS)
* Copy all files in ``/src`` to the root of the web directory
    * If you use the default website, a ``/web`` top level directory will exist on the shares
* Give permissions to the web site to read your photo directory
    * In DSM, go to File Station and set the permissions for the highest foler
    * Grant read permissions to the ``http`` user
* Change the configuration in ``slideconfig.json`` to what you prefer
* Setup the iPad:
    * If your home network has DNS configured for synology you can use mynas.local as the domain, otherwise just use the IP
    * Open Safari
    * Enter ``http://<my nas address/slideshow.php``
    * If you want full screen you can create a short cut by using the Share icon
    * You will want to disable screen lock after x minutes

### Acknowledgements
I had been putting off trying this because small projects always turn into a long saga, but [c0f](https://github.com/c0f/SimpleRandomPhotoSlideshow) allowed me to prototype and prove end to with just a couple of hours effort. It worked so forked the repo and extending it.


