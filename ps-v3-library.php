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
  private $start_time;             // Time that PsApiCall->get was called
  private $response_received_time; // Time that the API response was received

  // Constructs a PsApiCall object using the provided api key and catalog id.
  public function __construct($options) {

    $this->logger = new PsApiLogger;

    $valid_options = array('account', 'catalog', 'logging');

    foreach ($valid_options as $option) {
      if (isset($options[$option])) {
	if ($option == 'logging') {
	  $this->logger->enable();
	} else {
	  $this->options[$option] = $options[$option];
	}
      }
    }

    $this->called = false;

    $resources = array('merchants', 'products', 'deals', 'offers', 'categories', 'brands', 'deal_types', 'countries', 'merchant_types');
    foreach ($resources as $resource) {
      $this->{$resource} = array();
    }
  }

  // Calls the specified PopShops API, then parses the results into internal data structures. 
  // Parameter $call_type is a string. Valid values are 'products', 'merchants', or 'deals'. 
  // The value of $call_type directly selects which API will be called.
  // Parameter $arguments is an associative array mapping $argument=>$value pairs which will be passed to the API.
  // The values of $arguments must be relevant for the API specified by $call_type
  // Returns nothing.
  public function get($call_type='products', $arguments=array()) {
    $this->start_time = microtime(true);
    $this->logger->info("Setting up to call PopShops $call_type API...");

    if ($this->called) {
      $this->logger->error('Client attempted to call PsApiCall object more than once. Call aborted.');
      return;
    }

    if (! in_array($call_type, array('products', 'merchants', 'deals'))) {
      $this->logger->error("Invalid call_type '$call_type' was passed to PsApiCall->call. Call aborted.");
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
      $this->logger->error('Invalid status. Aborting call. Ensure all arguments passed to PsApiCall->get are valid.');
      return;
    }
    $this->logger->info("Processing JSON from $call_type call...");
    $this->processResults($parsed_json);
    $this->logger->info('JSON processing completed.');
    $this->logger->info('Internal processing time elapsed: ' . (string) (microtime(true) - $this->response_received_time));
    $this->logger->info('Total call time elapsed: ' . (string) (microtime(true) - $this->start_time));
  }

  // Retrieves an array of the given type of resource. $resource should be plural
  public function resource($resource) {
    $resource = strtolower($resource);
    if (isset($this->{$resource})) {
      return array_values($this->{$resource});
    } else {
      return array();
    }
  }

  // Retrieves an individual resource by its id. Accepts plural or singular $resource; behavior is identical
  public function resourceById($resource, $id) {
    if (array_key_exists( $id, $this->{$resource})) {
      return $this->{$resource}[$id];
    } else {
      return new PsApiDummy($this, $resource . " with id= $id is not present in PsApiCall results");
    }
  }

  private function processObjectJson($json, $class, $resource_name) {
    if (isset($json)) {
      foreach ($json as $object) {
        $this->logger->info('Internalizing ' . $resource_name . ' with ID=' . (string) $object['id']);
        $this->internalize($object, (new $class($this)), $this->{$resource_name});
      }
    }
  }

  private function processProductsJson($products_json) {
    foreach ($products_json as $product) {
      $this->logger->info('Internalizing product with ID=' . (string) $product['id']);
      $this->internalizeProduct($product);
    }
  }

  private function processDealsJson($json) {
    $this->processObjectJson($json, 'PsApiDeal', 'deals');
  }

  private function processMerchantsJson($json) {
    $this->processObjectJson($json, 'PsApiMerchant', 'merchants');
  }

  private function processBrandsJson($json) {
    $this->processObjectJson($json, 'PsApiBrand', 'brands');
  }

  private function processCategoriesJson($json) {
    $this->processObjectJson($json, 'PsApiCategory', 'categories');
  }

  private function processDealTypesJson($json) {
    $this->processObjectJson($json, 'PsApiDealType', 'deal_types');
  }

  private function processMerchantTypesJson($json) {
    $this->processObjectJson($json, 'PsApiMerchantType', 'merchant_types');
  }

  private function processCountriesJson($json) {
    $this->processObjectJson($json, 'PsApiCountry', 'countries');
  }

  private function processResults($json) {
    if (isset($json['results'])) {
      if (isset($json['results']['products']))
        $this->processProductsJson($json['results']['products']['product']);
      if (isset($json['results']['merchants']))
        $this->processMerchantsJson($json['results']['merchants']['merchant']);
      if (isset($json['results']['deals']))
        $this->processDealsJson($json['results']['deals']['deal']);
    }
    if (isset($json['resources'])) {
      if (isset($json['resources']['merchants']))
        $this->processMerchantsJson($json['resources']['merchants']['merchant']);
      if (isset($json['resources']['brands']))
        $this->processBrandsJson($json['resources']['brands']['brand']);
      if (isset($json['resources']['categories'])) {
        if (isset($json['resources']['categories']['matches'])) // Load from matches, if it exists
          $this->processCategoriesJson($json['resources']['categories']['matches']['category']);
        if (isset($json['resources']['categories']['context'])) // Load from context, if it exists
          $this->processCategoriesJson($json['resources']['categories']['context']['category']);
      }
      if (isset($json['resources']['deal_types']))
        $this->processDealTypesJson($json['resources']['deal_types']['deal_type']);
      if (isset($json['resources']['countries']))
        $this->processCountriesJson($json['resources']['countries']['country']);
      if (isset($json['resources']['merchant_types']))
        $this->processMerchantTypesJson($json['resources']['merchant_types']['merchant_type']);
      if (isset($json['resources']['deal_types']))
        $this->processDealTypesJson($json['resources']['deal_types']['deal_type']);
    }
  }

  // Takes the $json, puts its attributes and values into $object, and inserts it into $insert_into, keyed by $object's $json derived id
  private function internalize($json, $object, & $insert_into) {
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
	    $this->internalize($offer, (new PsApiOffer($this)), $this->offers);
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
}

abstract class PsApiResource {
  
  protected $attributes;
  protected $reference;
  
  public function __construct($reference) {
    $this->reference = $reference;
    $this->attributes = array();
  }

  // Retrieves and returns the attribute specified (attributes are dumb fields, such as names and ids)
  public function attr($attribute) {
    if (array_key_exists($attribute, $this->attributes)) {
      return $this->attributes[$attribute];
    } else {
      return 'PopShops API Error: Invalid attribute passed to ' . get_class($this) . '->attr: ' . $attribute;
    }
  }

  // Sets the given attribute to the given value. Should not be used by end-users of the PsApiCall library
  public function setAttr($attribute, $value) {
    $this->attributes[$attribute] = $value;
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

  public function largestImageUrl() {
    if (array_key_exists('image_url_large', $this->attributes)) {
      return $this->attributes['image_url_large'];
    } else if (array_key_exists('image_url_medium', $this->attributes)) {
      return $this->attributes['image_url_medium'];
    } else if (array_key_exists('image_url_small', $this->attributes)) {
      return $this->attributes['image_url_small'];
    } else {
      return 'No image url provided for product with ID=' . $this->attributes['id'];
    }
  }

  public function smallestImageUrl() {
    if (array_key_exists('image_url_small', $this->attributes)) {
      return $this->attributes['image_url_small'];
    } else if (array_key_exists('image_url_medium', $this->attributes)) {
      return $this->attributes['image_url_medium'];
    } else if (array_key_exists('image_url_large', $this->attributes)) {
      return $this->attributes['image_url_large'];
    } else {
      return 'No image url provided for product with ID=' . $this->attributes['id'];
    }
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
        return $this->reference->resourceById('categories', $this->attr('category'));
      case 'brand':
        return $this->reference->resourceById('brands', $this->attr('brand'));
    }
  }
}

class PsApiMerchant extends PsApiResource {

  private $offers;     // An array of offers from this merchant, if it's been cached already.
  private $deals;      // An array of deals from this merchant, if it's been cached already

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
      return $this->reference->resourceById('merchant_types', $this->attr('merchant_type'));
    case 'country':
      return $this->reference->resourceById('countries', $this->attr('country'));
    case 'category':
      return $this->reference->resourceById('categories', $this->attr('category'));
    }
  }
}

class PsApiDeal extends PsApiResource {
 
  private $deal_types;

  public function resource($resource) {
    switch ($resource) {
    case 'merchant':
      return $this->reference->resourceById('merchants', $this->attr('merchant'));
    case 'deal_types':
      if (isset($this->deal_types)) {
	return $this->deal_types;
      } else {
	$this->deal_types = array();
	$type_ids = explode(',', $this->attr('deal_type'));
	foreach ($type_ids as $type_id) {
	  $this->deal_types[] = $this->reference->resourceById('deal_types', $type_id);
	}
	return $this->deal_types;
      }
    }
  }
}

class PsApiOffer extends PsApiResource {

  private $product;

  public function setProduct($product) {
    $this->product = $product;
  }

  public function largestImageUrl() {
    if (array_key_exists('image_url_large', $this->attributes)) {
      return $this->attributes['image_url_large'];
    } else if (array_key_exists('image_url_medium', $this->attributes)) {
      return $this->attributes['image_url_medium'];
    } else if (array_key_exists('image_url_small', $this->attributes)) {
      return $this->attributes['image_url_small'];
    } else {
      return 'No image url provided for offer with ID=' . $this->attributes['id'];
    }
  }

  public function smallestImageUrl() {
    if (array_key_exists('image_url_small', $this->attributes)) {
      return $this->attributes['image_url_small'];
    } else if (array_key_exists('image_url_medium', $this->attributes)) {
      return $this->attributes['image_url_medium'];
    } else if (array_key_exists('image_url_large', $this->attributes)) {
      return $this->attributes['image_url_large'];
    } else {
      return 'No image url provided for offer with ID=' . $this->attributes['id'];
    }
  }

  // Retrieves the resource specified (resources are objects or arrays of objects somehow connected to this object)
  public function resource($resource) {
    switch ($resource) {
      case 'product':
        return $this->product;
      case 'merchant':
        return $this->reference->resourceById('merchants', $this->attr('merchant'));
    }
  }
}

class PsApiBrand extends PsApiResource {

  private $products;

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