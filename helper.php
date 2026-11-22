<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_astroapi
 * @copyright   (C) 2026
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Module\AstroApi\Site\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;

/**
 * Helper class for AstroAPI Module
 */
class AstroApiHelper
{
    /**
     * Get astronomical objects from AstroAPI
     *
     * @param   string  $url              The AstroAPI endpoint URL
     * @param   int     $itemsPerPage     Number of items to retrieve
     * @param   int     $cacheTime        Cache duration in seconds
     *
     * @return  array   Array of astronomical objects
     *
     * @throws  \Exception
     */
    public function getAstroObjects($url, $itemsPerPage = 10, $cacheTime = 3600)
    {
        $app = Factory::getApplication();
        $cacheKey = 'astroapi_objects_' . md5($url . $itemsPerPage);
        
        // Try to get from cache
        if ($cacheTime > 0) {
            $cachedData = $this->getFromCache($cacheKey);
            if ($cachedData !== false) {
                return $cachedData;
            }
        }
        
        try {
            // Create HTTP client
            $http = HttpFactory::getHttp();
            
            // Build request URL with limit parameter
            $requestUrl = rtrim($url, '/') . '/objects?limit=' . (int)$itemsPerPage;
            
            // Make HTTP request
            $response = $http->get($requestUrl);
            
            // Check if request was successful
            if ($response->code !== 200) {
                throw new \Exception('AstroAPI returned status code: ' . $response->code);
            }
            
            // Decode JSON response
            $data = json_decode($response->body, true);
            
            if ($data === null) {
                throw new \Exception('Invalid JSON response from AstroAPI');
            }
            
            // Normalize the data structure
            $data = $this->normalizeData($data);
            
            // Cache the result
            if ($cacheTime > 0) {
                $this->saveToCache($cacheKey, $data, $cacheTime);
            }
            
            return $data;
            
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Get single astronomical object by ID
     *
     * @param   string  $url        The AstroAPI endpoint URL
     * @param   int     $objectId   Object ID to retrieve
     * @param   int     $cacheTime  Cache duration in seconds
     *
     * @return  array   Astronomical object data
     *
     * @throws  \Exception
     */
    public function getAstroObject($url, $objectId, $cacheTime = 3600)
    {
        $cacheKey = 'astroapi_object_' . (int)$objectId;
        
        // Try to get from cache
        if ($cacheTime > 0) {
            $cachedData = $this->getFromCache($cacheKey);
            if ($cachedData !== false) {
                return $cachedData;
            }
        }
        
        try {
            // Create HTTP client
            $http = HttpFactory::getHttp();
            
            // Build request URL
            $requestUrl = rtrim($url, '/') . '/observation/' . (int)$objectId;
            
            // Make HTTP request
            $response = $http->get($requestUrl);
            
            if ($response->code !== 200) {
                throw new \Exception('AstroAPI returned status code: ' . $response->code);
            }
            
            // Decode JSON response
            $data = json_decode($response->body, true);
            
            if ($data === null) {
                throw new \Exception('Invalid JSON response from AstroAPI');
            }
            
            // Normalize the data
            $data = $this->normalizeItem($data);
            
            // Cache the result
            if ($cacheTime > 0) {
                $this->saveToCache($cacheKey, $data, $cacheTime);
            }
            
            return $data;
            
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Normalize API response data to standard structure
     *
     * @param   mixed  $data  Raw API response data
     *
     * @return  array  Normalized data array
     */
    private function normalizeData($data)
    {
        if (!is_array($data)) {
            return [];
        }
        
        return array_map([$this, 'normalizeItem'], $data);
    }
    
    /**
     * Normalize a single item to standard structure
     *
     * @param   array  $item  Raw API item
     *
     * @return  array  Normalized item
     */
    private function normalizeItem($item)
    {
        return [
            'id' => $item['id'] ?? null,
            'name' => $item['target_name'] ?? $item['observation_key'] ?? 'Unknown',
            'type' => 'Astronomical Object',
            'description' => $item['observation_key'] ?? 'No description available',
            'image' => $item['thumbnail'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'observation_time' => $item['observation_time'] ?? null,
            'target_name' => $item['target_name'] ?? null,
        ];
    }
    
    /**
     * Get data from cache
     *
     * @param   string  $key  Cache key
     *
     * @return  mixed   Cached data or false if not found
     */
    private function getFromCache($key)
    {
        $app = Factory::getApplication();
        $cacheDir = $app->get('cache_path', JPATH_CACHE);
        $cacheFile = $cacheDir . '/astroapi_' . $key . '.cache';
        
        if (file_exists($cacheFile)) {
            $cached = file_get_contents($cacheFile);
            $data = json_decode($cached, true);
            
            if ($data && isset($data['expires']) && $data['expires'] > time()) {
                return $data['value'];
            }
            
            unlink($cacheFile);
        }
        
        return false;
    }
    
    /**
     * Save data to cache
     *
     * @param   string  $key       Cache key
     * @param   mixed   $value     Data to cache
     * @param   int     $ttl       Time to live in seconds
     *
     * @return  void
     */
    private function saveToCache($key, $value, $ttl)
    {
        $app = Factory::getApplication();
        $cacheDir = $app->get('cache_path', JPATH_CACHE);
        $cacheFile = $cacheDir . '/astroapi_' . $key . '.cache';
        
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    }
}
