# Installation On Linux

RompЯ is a client for mpd or mopidy - you use RompЯ in a web browser to make mpd or mopidy play music
These are basic installation instructions for RompЯ on Linux, using the code you can download from here on github.

## Install MPD or Mopidy

Mpd should be available from your normal package manager. If you want to run Mopidy it is easy to install -  see [mopdy.com](http://www.mopidy.com).


### Player Connection Timeout

There is one thing you should adjust in the configuration for MPD and Mopidy

MPD and Mopidy both have a connection timeout parameter, after which time they will drop the connection between them and Rompr. This is seriously bad news for Rompr. You should make sure you increase it.

### For Mopidy

In mopidy.conf, your mpd section needs to contain

    [mpd]
    connection_timeout = 120

### For MPD

Somewhere in mpd.conf

    connection_timeout     "120"


If you have a very large music collection, the higher the numbeer the better. It is in seconds.


## Recommended Setup for Linux

This is the way I now recommend you do it. Thanks to the anonymous forum user who put up the initial instructions for getting it to work with PHP7 when I was in the wilderness.

_The following is a guide. It has been tested on Kubuntu 17.10 so Ubuntu and Debian flavours should follow this. Other distributions will be similar but package names may be different and the location of files may be different. Sorry, I can't try them all. If only they'd agree._

This guide sets up RompЯ to work with the nginx web server, an sqlite database and allows you to access it using a nice url - www.myrompr.net

### Install RompЯ

Download the latest release from [The Github Releases Page](https://github.com/fatg3erman/RompR/releases)

Let's assume you extracted the zip file into a folder called 'web' in your home directory. So now you have /home/YOU/web/rompr. From now on we're going to refer to that as /PATH/TO/ROMPR, because that's what programmers do and it makes the guide more general. You can put the code anywhere you like, although it won't work very well if you put it in the oven. So you'll need to look out for /PATH/TO/ROMPR in everything below and make sure you substitute the correct path.

### Set directory permissions

We need to create directories to store data in.

    cd /PATH/TO/ROMPR
    mkdir prefs
    mkdir albumart


And then we need to give nginx permission to write to them. We can do this by changing the ownership of those directories to be the user that nginx runs as. This may differ depending on which distro you're running, but this is good for all Ubuntus, where nginx runs as the user www-data.

    sudo chown www-data /PATH/TO/ROMPR/albumart
    sudo chown www-data /PATH/TO/ROMPR/prefs


### Install some packages

    sudo apt-get install nginx php-curl php-sqlite3 php-gd php-json php-xml php-mbstring php-fpm imagemagick


### Create nginx configuration

We're going to create RompЯ as a standalone website which will be accessible through the address www.myrompr.net

_Note. This sets RompЯ as the default site on your machine. For most people this will be the best configuration. If you are someone who cares about what that means and understands what that means, then you already know how to add RompЯ as the non-default site. What is described here is the easiest setup, which will work for most people_

Nginx comes set up with a default web site, which we don't want to use. You used to be able to just delete it but now we can't do that as it causes errors. So first we will edit the existing default config, since we don't want it to be the default

    sudo nano /etc/nginx/sites-available/default

Find the lines

    listen 80 default_server;
    listen [::]:80 default_server;

and change them to

    listen 80;
    listen [::]:80;

_Explnanation: The reason we want to set rompr as the default site on the machine is so we can easily access it from any device just by typing the machine's IP address into the browser_


Then we will create the rompr config and set that to be the default

    sudo nano /etc/nginx/sites-available/rompr

Paste in the following lines, remembering to change /PATH/TO/ROMPR as above.
Also there is a version number in there : php7.1-fpm.sock - the 7.1 will change depending on the version of PHP installed on your system. You should have noticed the version number when you installed the packages above. If you didn't, you'll have to figure it out by doing:

    apt-cache policy php-fpm

and you'll get something that looks like

    php-fpm:
        Installed: 7.0.30-0+deb9u1

There's a 7.0 in there, so I'd use php7.0-fpm.sock

    server {

        listen 80 default_server;
        listen [::]:80 default_server;

        root /PATH/TO/ROMPR;
        index index.php index.html index.htm;

        server_name www.myrompr.net;

        client_max_body_size 256M;

        # This section can be copied into an existing default setup
        location / {
            allow all;
            index index.php;
            location ~ \.php {
                    try_files $uri index.php =404;
                    fastcgi_pass unix:/var/run/php/php7.1-fpm.sock;
                    fastcgi_index index.php;
                    fastcgi_param SCRIPT_FILENAME $request_filename;
                    include /etc/nginx/fastcgi_params;
                    fastcgi_read_timeout 1800;
            }
            error_page 404 = /404.php;
            try_files $uri $uri/ =404;
            location ~ /albumart/* {
                    expires -1s;
            }
        }
    }

Save the file (Ctrl-X in nano, then answer 'Y'). Now link the configuration so it is enabled

    sudo ln -s /etc/nginx/sites-available/rompr /etc/nginx/sites-enabled/rompr

### Edit the hosts file

To make your browser capable of accessing www.myrompr.net we need to edit your hosts file so the computer knows where www.myrompr.net actually is.

On the computer where nginx is running you can use

    sudo nano /etc/hosts

and just add the line

    127.0.0.1        www.myrompr.net

On any other device you will have to edit /etc/hosts but you will need to use the full IP address of the computer running the nginx server. On devices where this is not possible - eg a mobile device - you can just enter the IP address of the machine running nginx into your browser to access RompЯ, because we have set RompЯ as the default site.

_Those of you who want to be clever and know how to edit hostname and DNS mapping on your router can do that, you will then not need RompЯ to be the default site and you will not need to edit the existing default config. Just remove default_server from the rompr configuration above and set server_name appopriately. If you didn't understand that, then ignore this paragraph._

### Edit PHP configuration

We need to edit the PHP configuration file. Again, note that there's a version number in this path which you'll need to make sure is correct

    sudo nano /etc/php/7.1/fpm/php.ini

Now find and modify (or add in if they're not there) the following parameters. Ctrl-W is 'find' in nano.

    allow_url_fopen = On
    memory_limit = 128M
    max_execution_time = 1800
    post_max_size = 256M
    upload_max_filesize = 10M
    max_file_uploads = 200

(The last 3 entries are really only used when uploading [Custom Background Images](/RompR/Theming). They set, respectively, the maximum size of an individual file (in megabytes), the maximum number of files you can upload in one go, and the maximum total size (megabytes) you can upload in one go. The values above are just examples - but note that post_max_size has an equivalent called 'client_max_body_size' in the nginx config file and it's sensible to keep them the same).

### That's all the configuring. Let's get everything running

    sudo systemctl restart php7.1-fpm
    sudo systemctl restart nginx

That should be it. Direct your browser to www.myrompr.net and all should be well.
