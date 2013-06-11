<?php

// ================ Musings ================

// Maybe provide a nice tree structure pre-built for categories? --Not viable, not enough categories returned with API call
// Easy way to explain attr vs resource in the documentation: attr gives you values, resource gives you objects
// Instead of "resource", maybe there really should be individual methods for each, because often, you need to pass an argument
// For example, offers for a given merchant AND product
// Ask about categories: context vs. matches, any sort of tree structure (even just parents)
// Set up a better log system... Flag for logging to page vs. logging to system error log? Something like that. Fine for now.
// Need to maintain order of elements in internal arrays...
// Ask about why not all referenced categories are returned. For example, do a products search for keyword 'fork'
// Some requests taking upwards of 4 seconds
// Internal processing time isn't the issue; it's the API response time
// Behavior when merchant/category isn't present, even though it's referenced somewhere in the API results

// ================  Code   ================

// Logger: A very, very basic logging class. Implements two log levels:
//   -info   (providing status updates and debug information)
//   -error  (providing information on critical, generally fatal errors)

class PsApiLogger {

  private $enable_flag; // If true, logging proceeds as usual. If false, logging is disabled.

  public function __construct() {
    date_default_timezone_set('America/Los_Angeles');
    $this->enable_flag = false; // Default behavior is no logging, this is PHP after all!
  }

  public function info($s) {
    if ($this->enable_flag) {
      print(date(DATE_RFC822) . ' INFO: ' . $s . '<br>');
    }
  }

  public function error($s) {
    if ($this->enable_flag) {
      print(date(DATE_RFC822) . ' ERROR: ' . $s . '<br>'); // Log to the page
      error_log(date(DATE_RFC822) . ' ERROR: ' . $s . '<br>'); // Log to the webserver's error log
    }
  }

  public function enable() {
    $this->enable_flag = true;
    $this->info('Logging enabled.');
  }

  public function disable() {
    $this->info('Logging disabled.');
    $this->enable_flag = false;
  }

}

// PsApiCall: A one-shot API request against PopShops' Merchants, Products, or Deals API
class PsApiCall {

  // Associative arrays mapping ids to objects
  private $merchants;    // Associative array mapping ids to merchants.
  private $products;     // Associative array mapping ids to products.
  private $deals;        // Associative array mapping ids to deals.
  private $offers;       // Associative array mapping ids to offers.
  private $categories;   // Associative array mapping ids to categories.
  private $brands;       // Associative array mapping ids to brands.
  private $deal_types;   // Associative array mapping ids to deal_types.
  private $countries;    // Associative array mapping ids to countries.

  // Various internal fields
  private $options;      // Associative array of option=>value pairs to be passed to the API when called.
  private $call_type;    // One of ['merchants', 'products', 'deals']. Specifies which api will be called.
  private $called;       // Set to true once API has been called once. Enforces single-use behavior of the PsApiCall object.
  private $logger;       // A Logger object used to log progress and errors

  // For statistics and analysis
  private $start_time;    // Time that PsApiCall->call was called
  private $response_received_time; // Time that the API response was received

  // Constructs a PsApiCall object using the provided api key and catalog id.
  public function __construct($api_key, $catalog_id) {
    $this->options['account'] = $api_key;
    $this->options['catalog'] = $catalog_id;
    $this->logger = new PsApiLogger;
    $this->logger->enable();
    $this->called = false;

    $this->merchants = array();
    $this->products = array();
    $this->deals = array();
    $this->offers = array();
    $this->categories = array();
    $this->brands = array();
    $this->deal_types = array();
    $this->countries = array();
  }

  // Calls the specified PopShops API, then parses the results into internal data structures. 
  // Parameter $call_type is a string. Valid values are 'products', 'merchants', or 'deals'. 
  // The value of $call_type directly selects which API will be called.
  // Parameter $arguments is an associative array mapping $argument=>$value pairs which will be passed to the API.
  // The values of $arguments must be relevant for the API specified by $call_type
  // Returns nothing.
  public function call($call_type, $arguments) {
    $this->start_time = microtime(true);
    $this->logger->info('Setting up to call PopShops ' . $call_type . ' API...');
    if ($this->called) {
      $this->logger->error('Client attempted to call PsApiCall object more than once. Call aborted.');
      return;
    }
    if (! in_array($call_type, array('products', 'merchants', 'deals'))) {
      	$this->logger->error('Invalid call_type "' . $call_type . '" was passed to PsApiCall->call. Call aborted.');
	return;
    }
    $this->call_type = $call_type;
    $this->called = true;
    $this->options = array_merge($this->options, $arguments);
    $formatted_options = array();
    foreach ($this->options as $key=>$value) $formatted_options[] = $key . '=' . urlencode($value);
    $url = 'http://api.popshops.com/v3/' . $call_type . '.json?' . implode('&', $formatted_options);
    $this->logger->info('Request URL: ' . $url);
    $this->logger->info('Sending request...');
    $raw_json = file_get_contents($url);
    $this->response_received_time = microtime(true);
    $this->logger->info('JSON file retrieved');
    $parsed_json = json_decode($raw_json, true);
    $this->logger->info('JSON file decoded');
    if ($parsed_json['status'] == '200') {
      $this->logger->info('API reported status 200 OK');
    } else {
      $this->logger->info('API reported unexpected status: ' . $parsed_json['status'] . '; Message: ' . $parsed_json['message']);
      $this->logger->error('Invalid status. Aborting call. Ensure all arguments passed to PsApiCall->call are valid.');
      return;
    }
    switch ($call_type) { // Based on the type of call, process the returned JSON accordingly
      case 'products':
	$this->logger->info('Processing JSON from products call...');
	$this->processProductsCall($parsed_json);
	break;
      case 'merchants':
	$this->logger->info('Processing JSON from merchants call...');
	$this->processMerchantsCall($parsed_json);
	break;
      case 'deals':
	$this->logger->info('Processing JSON from deals call...');
	$this->processDealsCall($parsed_json);
	break;
    }
    $this->logger->info('JSON processing completed.');
    $this->logger->info('Internal processing time: ' . (string) (microtime(true) - $this->response_received_time));
    $this->logger->info('Total call time: ' . (string) (microtime(true) - $this->start_time));
  }

  // Retrieves an array of the given type of resource. $resource should be plural
  // $sort_by can be one of 'relevance', 'price'
  // $descending, if true, will make returned element 0 have the highest value of $sort_by, while the last element will have the lowest
  // If $descending is false, the opposite will occur
  public function resource($resource, $sort_by='relevance', $descending=true) {
    switch($resource) {
    case 'products':
      return array_values($this->products);
    case 'offers':
      return array_values($this->offers);
    case 'merchants':
      return array_values($this->merchants);
    case 'deals':
      return array_values($this->deals);
    case 'deal_types':
      return array_values($this->deal_types);
    case 'categories':
      return array_values($this->categories);
    case 'brands':
      return array_values($this->brands);
    case 'countries':
      return array_values($this->countries);
    }
  }

  // Retrieves an individual resource by its id. Accepts plural or singular $resource; behavior is identical
  public function resourceById($resource, $id) {
    switch($resource) {
    case 'products':
    case 'product':
      return $this->products[$id];
    case 'offers':
    case 'offer':
      return $this->offers[$id];
    case 'merchants':
    case 'merchant':
      return $this->merchants[$id];
    case 'deals':
    case 'deal':
      return $this->deals[$id];
    case 'deal_types':
    case 'deal_type':
      return $this->deal_types[$id];
    case 'categories':
    case 'category':
      return $this->categories[$id];
    case 'brands':
    case 'brand':
      return $this->brands[$id];
    case 'countries':
    case 'country':
      return $this->countries[$id];
    }
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Products API
  private function processProductsCall($parsed_json) {

    // Load products
    $products = $parsed_json['results']['products']['product'];
    foreach ($products as $product) {
      $this->logger->info('Internalizing product with ID=' . (string) $product['id']);
      $this->internalizeProduct($product);
    }

    // Load deals
    $deals = $parsed_json['results']['deals']['deal'];
    foreach ($deals as $deal) {
      $this->logger->info('Internalizing deal with ID=' . (string) $deal['id']);
      $this->internalizeDeal($deal);
    }

    // Load merchants
    $merchants = $parsed_json['resources']['merchants']['merchant'];
    foreach ($merchants as $merchant) {
      $this->logger->info('Internalizing merchant with ID=' . (string) $merchant['id']);
      $this->internalizeMerchant($merchant);
    }
    
    // Load brands
    $brands = $parsed_json['resources']['brands']['brand'];
    foreach ($brands as $brand) {
      $this->logger->info('Internalizing brand with ID=' . (string) $brand['id']);
      $this->internalizeBrand($brand);
    }

    // Load categories from both matches and context
    if (array_key_exists('matches', $parsed_json['resources']['categories'])) { // There's two places that categories exists. The 'matches' subsection, and the 'context' subsection
      $categories = $parsed_json['resources']['categories']['matches']['category']; // Load from matches, if it exists
      foreach ($categories as $category) {
	$this->logger->info('Internalizing category with ID=' . (string) $category['id']);
	$this->internalizeCategory($category);
      }
    }
    if (array_key_exists('context', $parsed_json['resources']['categories'])) { // There's two places that categories exists. The 'matches' subsection, and the 'context' subsection
      $categories = $parsed_json['resources']['categories']['context']['category']; // Load from context, if it exists
      foreach ($categories as $category) {
	$this->logger->info('Internalizing category with ID=' . (string) $category['id']);
	$this->internalizeCategory($category);
      }
    }

    // Load deal types
    $deal_types = $parsed_json['resources']['deal_types']['deal_type'];
    foreach ($deal_types as $deal_type) {
      $this->logger->info('Internalizing deal type with ID=' . (string) $deal_type['id']);
      $this->internalizeDealType($deal_type);
    }
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Merchants API
  private function processMerchantsCall($parsed_json) {
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Deals API
  private function processDealsCall($parsed_json) {
  }

  // Takes the $json, puts its attributes and values into $object, and inserts it into $insert_into, keyed by $object's $json derived id
  private function genericInternalize($json, $object, & $insert_into) {
    foreach ($json as $attribute=>$value) {
      $object->setAttr($attribute, $value);
    }
    $insert_into[$object->attr('id')] = $object;
  }

  // Takes a chunk of decoded JSON representing a single Product (and any included offers)
  // Turns the JSON into a Product object, and appends it to the internal $products array, then turns any included Offer objects, and appends them to the internal $offers array
  private function internalizeProduct($product_json) {
    $tmp = new PsApiProduct($this);
    foreach ($product_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        case 'offers':
	  $offers_array = $value['offer'];
	  foreach ($offers_array as $offer) {
	    $this->internalizeOffer($offer);
	    $this->offers[$offer['id']]->setProduct($tmp); // Set the internalized offer's parent product to the currently internalized product
	    $tmp->addOffer($this->offers[$offer['id']]); // Add the offer we just internalized to the new (currently being internalized) product
	  }
	  break;
        default: // If there's no special case processing to do
	  if (is_numeric($value) or is_string($value)) { // Don't include any arrays or attributes that aren't just strings or numbers
	    $tmp->setAttr($attribute, $value);
	  }
      }
    }
    $this->products[$tmp->attr('id')] = $tmp;
  }

  private function internalizeMerchant($merchant_json) {
    $this->genericInternalize($merchant_json, (new PsApiMerchant($this)), $this->merchants);
  }

  private function internalizeOffer($offer_json) {
    $this->genericInternalize($offer_json, (new PsApiOffer($this)), $this->offers);
  }

  private function internalizeDeal($deal_json) {
    $this->genericInternalize($deal_json, (new PsApiDeal($this)), $this->deals);
  }

  private function internalizeBrand($brand_json) {
    $this->genericInternalize($brand_json, (new PsApiBrand($this)), $this->brands);
  }

  private function internalizeDealType($deal_type_json) {
    $this->genericInternalize($deal_type_json, (new PsApiDealType($this)), $this->deal_types);
  }

  private function internalizeCategory($category_json) {
    $this->genericInternalize($category_json, (new PsApiCategory($this)), $this->categories);
  }

  private function internalizeCountry($country_json) {
    $this->genericInternalize($country_json, (new PsApiCountry($this)), $this->countries);
  }
}

abstract class PsApiResource {
  
  protected $attr;
  protected $reference;
  
  public function __construct($reference) {
    $this->reference = $reference;
    $this->attr = array();
  }

  // Retrieves and returns the attribute specified (attributes are dumb fields, such as names and ids)
  public function attr($attribute) {
    if (array_key_exists($attribute, $this->attr)) {
      return $this->attr[$attribute];
    } else {
      return 'PopShops API Error: Invalid attribute passed to ' . get_class($this) . '->attr: ' . $attribute;
    }
  }

  // Sets the given attribute to the given value. Should not be used by end-users of the PsApiCall library
  public function setAttr($attribute, $value) {
    $this->attr[$attribute] = $value;
  }

  // Must be implemented by extended classes. Retrieves the specified resource (or array of resources) and returns it
  abstract public function resource($resource);
}

class PsApiProduct extends PsApiResource {

  private $offers;

  public function __construct($reference) {
    parent::__construct($reference);
    $this->offers = array();
  }

  public function addOffer($offer) { // This is a special case method, due to the strange way that the API returns offers (nested inside of products)
    $this->offers[] = $offer;
  }

  // Retrieves the resource specified (resources are objects or arrays of objects somehow connected to this object)
  public function resource($resource) {
    // If the resource has already been computed and cached, just use it. Otherwise, compute and cache it somewhere.
    // Big case statement for each possible type of resource
    // Likely going to be using $this->reference a lot
    switch ($resource) {
      case 'offers':
	return $this->offers; // Special case... No caching because of how offers are nested inside products
      case 'category':
	return $this->reference->resourceById('category', $this->attr('category'));
      case 'brand':
	return $this->reference->resourceById('brand', $this->attr('brand'));
    }
  }
}  

class PsApiMerchant extends PsApiResource {
  
  private $offers;     // An array of offers from this merchant, if it's been cached already.
  private $deals;      // An array of deals from this merchant, if it's been cached already

  public function __construct($reference) {
    parent::__construct($reference);
  }

  // Retrieves the resource specified (resources are objects or arrays of objects somehow connected to this object)
  public function resource($resource) {
    // If the resource has already been computed and cached, just use it. Otherwise, compute and cache it somewhere.
    // Big case statement for each possible type of resource
    // Likely going to be using $this->reference a lot
    switch ($resource) {
      case 'offers':
	if (isset($this->offers)) {
	  return $this->offers;
	} else {
	  $this->offers = array();
	  foreach ($this->reference->resource('offers') as $offer) {
	    if ($offer->attr('merchant') == $this->attr('id')) {
	      $this->offers[] = $offer;
	    }
	  }
	  return $this->offers;
	}
      case 'deals':
	if (isset($this->deals)) {
	  return $this->deals;
	} else {
	  $this->deals = array();
	  foreach ($this->reference->resource('deals') as $deal) {
	    if ($deal->attr('merchant') == $this->attr('id')) {
	      $this->deals[] = $deal;
	    }
	  }
	  return $this->deals;
	}
    }
  }
}

class PsApiDeal extends PsApiResource {
 
  private $deal_types;

  public function __construct($reference) {
    parent::__construct($reference);
  }

  public function resource($resource) {
    switch ($resource) {
    case 'merchant':
      return $this->reference->resource_by_id('merchant', $this->attr('merchant'));
    case 'deal_types':
      if (isset($this->deal_types)) {
	return $this->deal_types;
      } else {
	/*
	$temp_types = explode(',', $this->attr('deal_type'));
	foreach ($this->reference->resource('deal_types') as $deal_type) {
	  if (in_array((string) $deal_type->attr('id'), $temp_types)) {
	    $this->deal_types[] = $deal_type;
	  }
	  }*/
	$this->deal_types = array();
	$type_ids = explode(',', $this->attr('deal_type'));
	foreach ($type_ids as $type_id) {
	  $this->deal_types[] = $this->reference->resourceById('deal_type', $type_id);
	}
	return $this->deal_types;
      }
    }
  }
}

class PsApiOffer extends PsApiResource {

  private $product;

  public function __construct($reference) {
    parent::__construct($reference);
  }

  public function setProduct($product) {
    $this->product = $product;
  }

  // Retrieves the resource specified (resources are objects or arrays of objects somehow connected to this object)
  public function resource($resource) {
    // If the resource has already been computed and cached, just use it. Otherwise, compute and cache it somewhere.
    // Big case statement for each possible type of resource
    // Likely going to be using $this->reference a lot
    switch ($resource) {
      case 'product':
	return $this->product;
      case 'merchant':
        return $this->reference->resourceById('merchant', $this->attr('merchant'));
    }
  }
}

class PsApiBrand extends PsApiResource {
  
  private $products;

  public function __construct($reference) {
    parent::__construct($reference);
  }
  
  public function resource($resource) {
  }
}

class PsApiCategory extends PsApiResource {
  
  public function __construct($reference) {
    parent::__construct($reference);
  }
  
  public function resource($resource) {
    switch($resource) {
    case 'products':
      if (isset($this->products)) {
	return $this->products;
      } else {
	$this->products = array();
	foreach ($this->reference->resource('products') as $product) {
	  if (((string) $product->attr('category')) == ((string) $this->attr('id'))) {
	    $this->products[] = $product;
	  }
	}
	return $this->products;
      }
    }
  }
}

class PsApiDealType extends PsApiResource {
   
  private $deals;

  public function __construct($reference) {
    parent::__construct($reference);
  }
  
  public function resource($resource) {
    switch ($resource) {
    case 'deals':
      if (isset($this->deals)) {
	return $this->deals;
      } else {
	$this->deals = array();
	foreach ($this->reference->resource('deals') as $deal) {
	  $type_ids = explode(',', $deal->attr('deal_type'));
	  foreach ($type_ids as $type_id) {
	    if (((string) $type_id) == ((string) $this->attr('id'))) {
	      $this->deals[] = $deal;
	    }
	  }
	}
	return $this->deals;
      }
    }
  }
}

class PsApiCountry extends PsApiResource {
   
  public function __construct($reference) {
    parent::__construct($reference);
  }
  
  public function resource($resource) {
  }
}

?>