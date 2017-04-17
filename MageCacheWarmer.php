<?php
/**
 * A simple full page cache warmer for Magento 1 and 2.
 *
 * Minimal PHP dependencies:
 * Filter extension    - http://php.net/manual/en/book.filter.php
 * SimpleXML extension - http://php.net/manual/en/book.simplexml.php
 * allow_url_fopen = 1 - http://php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen
 *
 * Should work on old versions of PHP going back to 5.2?
 */
class MageCacheWarmer
{
    const MAX_TEST_URLS = 10;

    private
        $_iUnsecure,
        $_iDelay,
        $_sSitemapUrl,
        $_aSiteUrls,
        $_iNumUrls,
        $_cStatusCallback,
        $_fAvgDownloadTime,
        $_iTotalDownloadTime;

    public function getAvgDownloadTime()   { return $this->_fAvgDownloadTime;   }
    public function getTotalDownloadTime() { return $this->_iTotalDownloadTime; }

    /**
     * Download the sitemap for testing / warming.
     */
    public function __construct($sSitemapUrl, $cStatusCallback, $iDelay, $iUnsecure)
    {
        $this->_sSitemapUrl     = $sSitemapUrl;
        $this->_cStatusCallback = $cStatusCallback;
        $this->_iDelay          = $iDelay;
        $this->_iUnsecure       = $iUnsecure;
    }

    /**
     * Download and parse the sitemap.
     *
     * @note This must be called before test() or warm().
     *
     * @note $sSitemapXml is a local variable,
     *       there's no point to keep it in memory for the life of the object
     */
    public function loadSitemap()
    {
        $sSitemapXml = $this->_downloadSitemap($this->_sSitemapUrl);

        $this->_parseSitemap($sSitemapXml);

        return $this;
    }

    /**
     * Hit a random subset of the site's URLs to gauge performance.
     */
    public function test()
    {
        $aTestUrls = $this->_aSiteUrls;
        $iNumUrls  = $this->_iNumUrls;

        // Truncate the list of URLs to self::MAX_TEST_URLS if the site has more
        if($this->_iNumUrls > self::MAX_TEST_URLS) {
            $aTestUrls = self::array_random($this->_aSiteUrls, self::MAX_TEST_URLS);
            $iNumUrls  = self::MAX_TEST_URLS;
        }

        call_user_func($this->_cStatusCallback, "Testing with $iNumUrls URLs" . PHP_EOL);

        $this->_run($aTestUrls);

        call_user_func(
            $this->_cStatusCallback,
            "Average page time is {$this->_fAvgDownloadTime}" . PHP_EOL);
    }

    /**
     * Test the site to get an initial reading of its performance.
     * Then run the tool across the given set of URLs.
     * Lastly, test the site again so we can determine the performance gain from caching.
     */
    public function warm()
    {
        // Run the initial test
        $this->test($this->_sSitemapUrl);

        $fOrigAvgTime = $this->_fAvgDownloadTime;

        // Now warm the cache for the entire site
        call_user_func(
            $this->_cStatusCallback,
            PHP_EOL . "Warming {$this->_iNumUrls} URLs" . PHP_EOL);

        $this->_run($this->_aSiteUrls);

        call_user_func($this->_cStatusCallback, PHP_EOL);

        // Finally, test the site again
        $this->test($this->_sSitemapUrl);

        $fCachedAvgTime = $this->_fAvgDownloadTime;

        // Return the speedup as a percentage of the original performance
        $fChange = self::calcChange($fOrigAvgTime, $fCachedAvgTime);

        return round(100 * $fChange, 2);
    }

    /** 
     * Calculate the relative difference between a starting and ending time.
     * You would multiply this by 100 and round by 2 to see a human readable value.
     */
    static public function calcChange($fStartingTime, $fEndingTime)
    {   
        $fMinTime    = min($fEndingTime, $fStartingTime);
        $fMaxTime    = max($fEndingTime, $fStartingTime);
        $fChange      = $fMaxTime - $fMinTime;

        if($fChange < .01) {
            return 0;
        }   

        $fDelta = $fChange / $fStartingTime;

        return $fDelta;
    }

    /**
     * Download the URLs, timing each one
     */
    private function _run(array $aUrls)
    {
        $iNumUrls           = count($aUrls);
        $iTotalDownloadTime = 0;

        foreach($aUrls as $i => $sUrl) {
            // Log the request
            $iCur = $i + 1;
            call_user_func(
                $this->_cStatusCallback,
                "($iCur/{$iNumUrls}) - Fetching " . $sUrl . PHP_EOL);

            // Note the start time and download the page
            $iPageStartTime = microtime(true);
            $streamContext['http'] = array(
                    'header' => array(
                        'User-Agent: WFPC Cache Warmer'
                    )
                );
            if ($this->_iUnsecure) {
                    $streamContext['ssl'] = array(
                            'verify_peer'=>false,
                            'verify_peer_name'=>false,
                    );
            }
            file_get_contents($sUrl,false, stream_context_create($streamContext));
        
            // Update the total download time
            $iTotalDownloadTime += microtime(true) - $iPageStartTime;

            // Sleep between requests if we're told to
            if($this->_iDelay > 0) {
                sleep($this->_iDelay);
            }
        }

        // Store the average download time
        $this->_fAvgDownloadTime   = $iTotalDownloadTime * 1000 / $iNumUrls;
        $this->_iTotalDownloadTime = $iTotalDownloadTime;
    }

    /**
     * Validate the sitemap url, download the sitemap and store it on this object
     */
    private function _downloadSitemap($sSitemapUrl) 
    {
        // Grab the sitemap URL from the CLI and verify it looks like a URL
        if(filter_var($sSitemapUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(
                "$sSitemapUrl is not a valid URL" . PHP_EOL);
        }
        $this->_sSitemapUrl = $sSitemapUrl;
        
        // Stream context for file_get_contents(),
        // some webservers return a 503 error when no user agent is set.
        $streamContext['http'] = array(
                'header' => array(
                    'User-Agent: WFPC Cache Warmer'
                )
            );
        if ($this->_iUnsecure) {
                $streamContext['ssl'] = array(
                        'verify_peer'=>false,
                        'verify_peer_name'=>false,
                );
        }
        // Try downloading the sitemap file
        $sSitemapXml = file_get_contents($sSitemapUrl, false, stream_context_create($streamContext));
        if(!$sSitemapXml) {
            throw new RuntimeException(
                'Unable to download the sitemap file at $sSitemapUrl' . PHP_EOL);
        }

        return $sSitemapXml;
    }

    /**
     * Parse the sitemap into structures we can use for further processing.
     */
    private function _parseSitemap($sSitemapXml)
    {
        // Try to parse the sitemap file via Simple XML
        try {
            $oSitemap = new SimpleXMLElement($sSitemapXml);
        } catch(Exception $e) {
            throw new RuntimeException(
                'Failed to parse the sitemap file' . PHP_EOL . $e->getMessage() . PHP_EOL);
        }

        // Extract the list of URLs from the sitemap that we intend to crawl
        $aDocNamespaces = $oSitemap->getDocNamespaces();
        $sXmlns         = array_shift($aDocNamespaces);

        $oSitemap->registerXPathNamespace('sitemap', $sXmlns);

        $this->_aSiteUrls = $oSitemap->xpath("//sitemap:loc");
        $this->_iNumUrls  = count($this->_aSiteUrls);
    }

    /**
     * Format milliseconds nicely.
     */
    static public function format_milli($ms)
    {
        $ms = (int)$ms;
        return
            floor($ms/3600000) . ':' .                        // hours
            floor($ms/60000) . ':' .                          // minutes
            floor(($ms % 60000) / 1000) . '.' .               // seconds
            str_pad(floor($ms % 1000), 3, '0', STR_PAD_LEFT); // milliseconds
    }

    /**
     * Randomly select items from an array
     * I think I lifted this from somehwere, replace or credit said source...
     */
    static public function array_random(array $arr, $num=1)
    {
        shuffle($arr);

        $r = array();
        for($i = 0; $i < $num; $i++) {
            $r[] = $arr[$i];
        }

        return $num == 1 ? $r[0] : $r;
    }
}
