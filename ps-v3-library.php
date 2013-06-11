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
  private $merchants;      // Associative array mapping ids to merchants.
  private $products;       // Associative array mapping ids to products.
  private $deals;          // Associative array mapping ids to deals.
  private $offers;         // Associative array mapping ids to offers.
  private $categories;     // Associative array mapping ids to categories.
  private $brands;         // Associative array mapping ids to brands.
  private $deal_types;     // Associative array mapping ids to deal_types.
  private $countries;      // Associative array mapping ids to countries.
  private $merchant_types; // Associative array mapping ids to merchant_types.

  // Various internal fields
  private $options;      // Associative array of option=>value pairs to be passed to the API when called.
  private $call_type;    // One of ['merchants', 'products', 'deals']. Specifies which api will be called.
  private $called;       // Set to true once API has been called once. Enforces single-use behavior of the PsApiCall object.
  private $logger;       // A Logger object used to log progress and errors

  // For statistics and analysis
  private $start_time;             // Time that PsApiCall->call was called
  private $response_received_time; // Time that the API response was received

  // Constructs a PsApiCall object using the provided api key and catalog id.
  public function __construct($api_key, $catalog_id, $logging=false) {
    $this->options['account'] = $api_key;
    $this->options['catalog'] = $catalog_id;
    $this->logger = new PsApiLogger;
    if ($logging) $this->logger->enable();
    $this->called = false;

    $this->merchants = array();
    $this->products = array();
    $this->deals = array();
    $this->offers = array();
    $this->categories = array();
    $this->brands = array();
    $this->deal_types = array();
    $this->countries = array();
    $this->merchant_types = array();
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
    $this->logger->info('Internal processing time elapsed: ' . (string) (microtime(true) - $this->response_received_time));
    $this->logger->info('Total call time elapsed: ' . (string) (microtime(true) - $this->start_time));
  }

  // Retrieves an array of the given type of resource. $resource should be plural
  // $sort_by can be one of 'relevance', 'price'
  // $descending, if true, will make returned element 0 have the highest value of $sort_by, while the last element will have the lowest
  // If $descending is false, the opposite will occur
  public function resource($resource, $sort_by='relevance', $descending=true) {
    switch($resource) {
    case 'products':
    case 'Products':
      return array_values($this->products);
    case 'offers':
    case 'Offers':
      return array_values($this->offers);
    case 'merchants':
    case 'Merchants':
      return array_values($this->merchants);
    case 'deals':
    case 'Deals':
      return array_values($this->deals);
    case 'deal_types':
    case 'DealTypes':
      return array_values($this->deal_types);
    case 'categories':
    case 'Categories':
      return array_values($this->categories);
    case 'brands':
    case 'Brands':
      return array_values($this->brands);
    case 'countries':
    case 'Countries':
      return array_values($this->countries);
    case 'merchant_types':
    case 'MerchantTypes':
      return array_values($this->merchant_types);
    }
  }

  // Retrieves an individual resource by its id. Accepts plural or singular $resource; behavior is identical
  public function resourceById($resource, $id) {
    switch($resource) {
    case 'products':      
    case 'product':
    case 'Products':
    case 'Product':
      if (array_key_exists($id, $this->products)) {
	return $this->products[$id];
      } else {
	return new PsApiDummy($this, 'Product with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'offers':
    case 'offer':
    case 'Offers':
    case 'Offer':
      if (array_key_exists($id, $this->offers)) {
	return $this->offers[$id];
      } else {
	return new PsApiDummy($this, 'Offer with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'merchants':
    case 'merchant':
    case 'Merchants':
    case 'Merchant':
      if (array_key_exists($id, $this->merchants)) {
	return $this->merchants[$id];
      } else {
	return new PsApiDummy($this, 'Merchant with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'deals':
    case 'deal':
    case 'Deals':
    case 'Deal':
      if (array_key_exists($id, $this->deals)) {
	return $this->deals[$id];
      } else {
	return new PsApiDummy($this, 'Deal with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'deal_types':
    case 'deal_type':
    case 'DealTypes':
    case 'DealType':
      if (array_key_exists($id, $this->deal_types)) {
	return $this->deal_types[$id];
      } else {
	return new PsApiDummy($this, 'DealType with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'categories':
    case 'category':
    case 'Categories':
    case 'Category':
      if (array_key_exists($id, $this->categories)) {
	return $this->categories[$id];
      } else {
	return new PsApiDummy($this, 'Category with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'brands':
    case 'brand':
    case 'Brands':
    case 'Brand':
      if (array_key_exists($id, $this->brands)) {
	return $this->brands[$id];
      } else {
	return new PsApiDummy($this, 'Brand with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'countries':
    case 'country':
    case 'Countries':
    case 'Country':
      if (array_key_exists($id, $this->countries)) {
	return $this->countries[$id];
      } else {
	return new PsApiDummy($this, 'Country with id=' . $id . ' is not present in PsApiCall results');
      }
    case 'merchant_types':
    case 'merchant_type':
    case 'MerchantTypes':
    case 'MerchantType':
      if (array_key_exists($id, $this->merchant_types)) {
	return $this->merchant_types[$id];
      } else {
	return new PsApiDummy($this, 'MerchantType with id=' . $id . ' is not present in PsApiCall results');
      }
    }
  }

  private function processProductsJson($products_json) {
    foreach ($products_json as $product) {
      $this->logger->info('Internalizing product with ID=' . (string) $product['id']);
      $this->internalizeProduct($product);
    }
  }

  private function processDealsJson($deals_json) {
    foreach ($deals_json as $deal) {
      $this->logger->info('Internalizing deal with ID=' . (string) $deal['id']);
      $this->internalizeDeal($deal);
    }
  }

  private function processMerchantsJson($merchants_json) {
    foreach ($merchants_json as $merchant) {
      $this->logger->info('Internalizing merchant with ID=' . (string) $merchant['id']);
      $this->internalizeMerchant($merchant);
    }
  }

  private function processBrandsJson($brands_json) {
    foreach ($brands_json as $brand) {
      $this->logger->info('Internalizing brand with ID=' . (string) $brand['id']);
      $this->internalizeBrand($brand);
    }
  }

  private function processCategoriesJson($categories_json) {
    foreach ($categories_json as $category) {
	$this->logger->info('Internalizing category with ID=' . (string) $category['id']);
	$this->internalizeCategory($category);
    }
  }

  private function processDealTypesJson($deal_types_json) {
    foreach ($deal_types_json as $deal_type) {
      $this->logger->info('Internalizing deal type with ID=' . (string) $deal_type['id']);
      $this->internalizeDealType($deal_type);
    }
  }

  private function processMerchantTypesJson($merchant_types_json) {
    foreach ($merchant_types_json as $merchant_type) {
      $this->logger->info('Internalizing merchant type with ID=' . (string) $merchant_type['id']);
      $this->internalizeMerchantType($merchant_type);
    }
  }

  private function processCountriesJson($countries_json) {
    foreach ($countries_json as $country) {
      $this->logger->info('Internalizing country with ID=' . (string) $country['id']);
      $this->internalizeCountry($country);
    }
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Products API
  private function processProductsCall($parsed_json) {
    $this->processProductsJson($parsed_json['results']['products']['product']);
    $this->processDealsJson($parsed_json['results']['deals']['deal']);
    $this->processMerchantsJson($parsed_json['resources']['merchants']['merchant']);
    $this->processBrandsJson($parsed_json['resources']['brands']['brand']);
    if (array_key_exists('matches', $parsed_json['resources']['categories'])) // Load from matches, if it exists
      $this->processCategoriesJson($parsed_json['resources']['categories']['matches']['category']);
    if (array_key_exists('context', $parsed_json['resources']['categories'])) // Load from context, if it exists
      $this->processCategoriesJson($parsed_json['resources']['categories']['context']['category']);
    $this->processDealTypesJson($parsed_json['resources']['deal_types']['deal_type']);
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Merchants API
  private function processMerchantsCall($parsed_json) {
    $this->processMerchantsJson($parsed_json['results']['merchants']['merchant']);
    if (array_key_exists('matches', $parsed_json['resources']['categories'])) // Load from matches, if it exists
      $this->processCategoriesJson($parsed_json['resources']['categories']['matches']['category']);
    if (array_key_exists('context', $parsed_json['resources']['categories'])) // Load from context, if it exists
      $this->processCategoriesJson($parsed_json['resources']['categories']['context']['category']);
    $this->processCountriesJson($parsed_json['resources']['countries']['country']);
    $this->processMerchantTypesJson($parsed_json['resources']['merchant_types']['merchant_type']);
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Deals API
  private function processDealsCall($parsed_json) {
    $this->processDealsJson($parsed_json['results']['deals']['deal']);
    $this->processDealTypesJson($parsed_json['resources']['deal_types']['deal_type']);
    $this->processMerchantTypesJson($parsed_json['resources']['merchant_types']['merchant_type']);
    $this->processCountriesJson($parsed_json['resources']['countries']['country']);
    $this->processMerchantsJson($parsed_json['resources']['merchants']['merchant']);
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

  private function internalizeMerchantType($merchant_type_json) {
    $this->genericInternalize($merchant_type_json, (new PsApiMerchantType($this)), $this->merchant_types);
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
    case 'merchant_type':
      return $this->reference->resourceById('merchant_type', $this->attr('merchant_type'));
    case 'country':
      return $this->reference->resourceById('country', $this->attr('country'));
    case 'category':
      return $this->reference->resourceById('category', $this->attr('category'));
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
      return $this->reference->resourceById('merchant', $this->attr('merchant'));
    case 'deal_types':
      if (isset($this->deal_types)) {
	return $this->deal_types;
      } else {
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
    switch($resource) {
    case 'products':
      if (isset($this->products)) {
	return $this->products;
      } else {
	$this->products = array();
	  foreach ($this->reference->resource('products') as $product) {
	    if ($product->attr('brand') == $this->attr('id')) {
	      $this->products[] = $product;
	    }
	  }
	  return $this->products;
      }
    }
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
   
  private $merchants;

  public function __construct($reference) {
    parent::__construct($reference);
  }
  
  public function resource($resource) {
    switch($resource) {
    case 'merchants':
      if (isset($this->merchants)) {
	return $this->merchants;
      } else {
	$this->merchants = array();
	foreach ($this->reference->resource('merchants') as $merchant) {
	  if (((string) $merchant->attr('country')) == ((string) $this->attr('id'))) {
	    $this->merchants[] = $merchant;
	  }
	}
	return $this->merchants;
      }
    }
  }
}

class PsApiMerchantType extends PsApiResource {

  private $merchants;

  public function __construct($reference) {
    parent::__construct($reference);
  }

  public function resource($resource) {
    switch($resource) {
    case 'merchants':
      if (isset($this->merchants)) {
	return $this->merchants;
      } else {
	$this->merchants = array();
	foreach ($this->reference->resource('merchants') as $merchant) {
	  if (((string) $merchant->attr('merchant_type')) == ((string) $this->attr('id'))) {
	    $this->merchants[] = $merchant;
	  }
	}
	return $this->merchants;
      }
    }
  }
}

// Meant to be returned when an error occurs
class PsApiDummy extends PsApiResource {
  
  private $message;

  public function __construct($reference, $message=null) {
    parent::__construct($reference);
    if (isset($message)) {
      $this->message = $message;
    }
  }

  public function attr($attribute) {
    if (isset($this->message)) {
      return '[' . $this->message . ']';
    } else {
      return '[This element does not exist]';
    }
  }

  public function resource($resource) {
    if (isset($this->message)) {
      return new PsApiDummy($reference, 'Parent object message: ' . $this->message);
    } else {
      return new PsApiDummy($reference);
    }
  }
}

?>