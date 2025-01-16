<?php

/**
 * Fetch all pages of data from an API
 * 
 * @param $client - Guzzle client
 * @param $uri - API endpoint
 * @param $query - query parameters
 * @param $pages - number of pages to fetch
 * @param $cache - amount of time in minutes to cache results
 * 
 * @return array - all data from all pages
 */
public function fetchAllPages( $client, $uri, $query, $pages, $cache ) {
    // create a copy of the query array into a new variable to manipulate for cache key
    $cache_query = $query;
    
    // create string from $query array
    $query_string = http_build_query($cache_query);
    
    // append query string to url
    $cache_key = $uri . $query_string;
    
    $cache_duration = now()->addHours(12);
    
    // Check if the data is already cached
    if (Cache::has($cache_key)) {
        return Cache::get($cache_key);
    }
    
    $currentPage = 1;
    $promises = [];
    $allData = [];

    while ( $currentPage <= $pages) {
        Log::info('Fetching page ' . $currentPage . ' of ' . $pages . ' for ' . $uri);
        $query['offset'] = ($currentPage - 1) * 100;

        // if promises contains 10 requests process them and reset promises
        if (count($promises) === 10) {
            // Wait for all the requests to complete;
            // Does not throw an exception if any of the requests fail
            $results = Promise\Utils::settle($promises)->wait();

            // Process results
            foreach ($results as $result) {
                if ($result['state'] === 'rejected') {
                    Log::error('Request failed', ['reason' => $result['reason']]);
                    continue;
                }

                $allData = array_merge($allData, $result['value']);
            }

            // reset promises
            $promises = [];
        }
        
        $promise = $client->getAsync($uri, [
            'query' => $query,
            'headers' => $this->get_etsy_headers()
        ])->then(
            function ($response) {
                $data = $this->json( $response );
                
                return $data->results;
            }
        );
        $promises[] = $promise;
        $currentPage++;
    }

    // if any promises are left process them
    if (count($promises) > 0) {
        // Wait for all the requests to complete;
        // Does not throw an exception if any of the requests fail
        $results = Promise\Utils::settle($promises)->wait();

        // Process results
        foreach ($results as $result) {
            // dd($result['value']);
            if ($result['state'] === 'rejected') {
                Log::error('Request failed', ['reason' => $result['reason']]);
                continue;
            }

            $allData = array_merge($allData, $result['value']);
        }
    }
    
    // Cache the data for future use
    if ( !empty($allData)) {
        Cache::put($cache_key, $allData, $cache_duration);
    }

    return $allData;
}
