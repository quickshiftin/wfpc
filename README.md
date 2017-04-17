# wfpc

FREE Magento full page cache warmer

`wfpc` relies on a sitemap.xml file; you can read how to generate one for your Magento site  [here](http://alanstorm.com/generating_google_sitemaps_in_magento). Once you have a sitemap generated (and an FPC configured), you're good to hit the site with the cache warmer.

## Usage
### Testing Performance
`wfpc` has two modes of operation, the first is *test* mode. This randomly selects 10 URLs from your sitemap to load. An example
```
./wfpc -t http://mymagentosite.com/sitemap.xml
```
You'll see a fancy summary of your site's performance
```shell
Finished testing your Magento site performance
Total download time (in seconds)   : 5.0269110202789
Total download time (formatted)    : 0:0:5.026
Average page time (in milliseconds): 502.69110202789
```

### Warming the FPC
To actually warm the FPC, use `-w` as the first argument. `wfpc` will fetch each URL listed in sitemap.xml synchronously.

The warmer will run an initial test, warm your entire site, then test again and report on performance gain

```shell
Finished warming your Magento site performance
Average page time (in milliseconds): 517.31648445129
Speedup is 75.07%
```

#### Delayed requests
If you want to put a delay between requests, you can add a `-d=delay-seconds` after the `-w` argument. An example

##### No delay
```
./wfpc -w http://mymagentosite.com/sitemap.xml
```
##### With 1 second delay
```
./wfpc -w -d=1 http://mymagentosite.com/sitemap.xml
```
#### Test or Run on usecure urls
```
./wfpc -k -t https://192.169.1.234/sitemap.xml
./wfpc -k -w https://192.169.1.234/sitemap.xml
```
If you have a large site with a lot of URLs to warm, you might consider running `wfpc` within a [`screen`](http://www.gnu.org/software/screen/manual/screen.html) session.

## Magento 2 FPC configuration tips
 * Install and configure Varnish, that's [the recommendation from Magento](http://devdocs.magento.com/guides/v2.0/config-guide/varnish/config-varnish.html)

## Magento 1 FPC configuration tips
 * [Lesti FPC](https://gordonlesti.com/projects/lestifpc/) is a free full page cache and it works well
 * Make sure you have a reasonably high TTL for your FPC. If your pages expire from the FPC quickly, there's not much point to warming them all!
 * Disk-based FPC caching seems practically just as beneficial as memory-based caching on SSD servers. Unless you really need too, you're probably better off only using memory to cache core Magento data and using the disk for your FPC.
 * If you're using a memory-based store for your FPC like APC, Redis or Memcache, keep an eye on the usage of the store as your cache is warming. For example, if you have a large site you want to cache, you may overrun your cache storage limit if you're not careful!

## Notes
* The script should run on Windows, but I haven't tested it there. You'll probably need to run it as `php wfpc args...` in that case.
* The script relies upon a few things in PHP
  - Filter extension
  - SimpleXML extension
  - allow_url_fopen = 1
* This script will actually work for *any* site that has a sitemap.xml file. If you have an FPC mechanism on a site running something other than Magento, you may still find the script useful!
