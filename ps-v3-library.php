<?php

// ================ Musings ================

// Maybe provide a nice tree structure pre-built for categories? --Not viable, not enough categories returned with API call
// Three different inherited PopShopsApi types? Or just a flag? --Nope, just a flag. Much easier
// Easy way to explain attr vs resource in the documentation: attr gives you values, resource gives you objects
// Instead of "resource", maybe there really should be individual methods for each, because often, you need to pass an argument
// For example, offers for a given merchant AND product
// Ask about categories: context vs. matches, any sort of tree structure (even just parents)
// Set up a better log system... Flag for logging to page vs. logging to system error log? Something like that. Fine for now.

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

// PopShopsApi: A one-shot API request against PopShops' Merchants, Products, or Deals API
class PopShopsApi {

  private $merchants;    // Associative array mapping ids to merchants.
  private $products;     // Associative array mapping ids to products.
  private $deals;        // Associative array mapping ids to deals.
  private $offers;       // Associative array mapping ids to offers.
  private $categories;   // Associative array mapping ids to categories.
  private $brands;       // Associative array mapping ids to brands.
  private $deal_types;   // Associative array mapping ids to deal_types.
  private $options;      // Associative array of option=>value pairs to be passed to the API when called.
  private $call_type;    // One of ['merchants', 'products', 'deals']. Specifies which api will be called.
  private $called;       // Set to true once API has been called once. Enforces single-use behavior of the PopShopsApi object.
  private $logger;       // A Logger object used to log progress and errors

  // Constructs a PopShopsApi object using the provided api key and catalog id.
  public function __construct($api_key, $catalog_id) {
    $this->options['account'] = $api_key;
    $this->options['catalog'] = $catalog_id;
    $this->logger = new PsApiLogger;
    $this->logger->enable();
    $this->called = false;
  }

  // Calls the specified PopShops API, then parses the results into internal data structures. 
  // Parameter $call_type is a string. Valid values are 'products', 'merchants', or 'deals'. 
  // The value of $call_type directly selects which API will be called.
  // Parameter $arguments is an associative array mapping $argument=>$value pairs which will be passed to the API.
  // The values of $arguments must be relevant for the API specified by $call_type
  // Returns nothing.
  public function call($call_type, $arguments) {
    $this->logger->info('Setting up to call PopShops ' . $call_type . ' API...');
    if ($this->called) {
      $this->logger->error('Client attempted to call PopShopsApi object more than once. Call aborted.');
      return;
    }
    if (! in_array($call_type, array('products', 'merchants', 'deals'))) {
      	$this->logger->error('Invalid call_type "' . $call_type . '" was passed to PopShopsApi->call. Call aborted.');
	return;
    }
    $this->call_type = $call_type;
    $this->called = true;
    $this->options = array_merge($this->options, $arguments);
    $formatted_options = array();
    foreach ($this->options as $key=>$value) array_push($formatted_options, $key . '=' . urlencode($value));
    $url = 'http://api.popshops.com/v3/' . $call_type . '.json?' . implode('&', $formatted_options);
    $this->logger->info('Request URL: ' . $url);
    $this->logger->info('Sending request...');
    $raw_json = file_get_contents($url);
    $this->logger->info('JSON file retrieved');
    $parsed_json = json_decode($raw_json, true);
    $this->logger->info('JSON file decoded');
    if ($parsed_json['status'] == '200') {
      $this->logger->info('API reported status 200 OK');
    } else {
      $this->logger->info('API reported unexpected status: ' . $parsed_json['status'] . '; Message: ' . $parsed_json['message']);
      $this->logger->error('Invalid status. Aborting call. Ensure all arguments passed to PopShopsApi->call are valid.');
      return;
    }
    switch ($call_type) { // Based on the type of call, process the returned JSON accordingly
      case 'products':
	$this->logger->info('Processing JSON from products call...');
	$this->process_products_call($parsed_json);
	break;
      case 'merchants':
	$this->logger->info('Processing JSON from merchants call...');
	$this->process_merchants_call($parsed_json);
	break;
      case 'deals':
	$this->logger->info('Processing JSON from deals call...');
	$this->process_deals_call($parsed_json);
	break;
    }
    $this->logger->info('JSON processing completed.');
  }

  public function get_products() { // Returns an array of all of the products. Keys by a normal index, rather than by id
    $ret = array();
    foreach ($this->products as $id=>$product) {
      array_push($ret, $product);
    }
    return $ret;
  }

  public function get_merchants() { // Returns an array of all of the merchants. Keys by a normal index, rather than by id
    $ret = array();
    foreach ($this->merchants as $id=>$merchant) {
      array_push($ret, $merchant);
    }
    return $ret;
  }

  public function get_offers() { // Returns an array of all of the offers. Keys by a normal index, rather than by id
    $ret = array();
    foreach ($this->offers as $id=>$offer) {
      array_push($ret, $offer);
    }
    return $ret;
  }

  public function get_deals() { // Returns an array of all of the deals. Keys by a normal index, rather than by id
    $ret = array();
    foreach ($this->deals as $id=>$deal) {
      array_push($ret, $deal);
    }
    return $ret;
  }

  public function get_deal_types() { // Returns an array of all of the deal_types. Keys by a normal index, rather than by id
    $ret = array();
    foreach ($this->deal_types as $id=>$deal_type) {
      array_push($ret, $deal_type);
    }
    return $ret;
  }

  public function get_categories() { // Returns an array of all of the categories. Keys by a normal index, rather than by id
    $ret = array();
    foreach ($this->categories as $id=>$category) {
      array_push($ret, $category);
    }
    return $ret;
  }

  public function get_brands() { // Returns an array of all of the brands. Keys by a normal index, rather than by id
    $ret = array();
    foreach ($this->brands as $id=>$brand) {
      array_push($ret, $brand);
    }
    return $ret;
  }

  public function get_product($id) { // Returns the product with the given id, if it exists
    return $this->products[$id];
  }

  public function get_offer($id) { // Returns the offer with the given id, if it exists
    return $this->offers[$id];
  }

  public function get_deal($id) { // Returns the deal with the given id, if it exists
    return $this->deals[$id];
  }

  public function get_merchant($id) { // Returns the merchant with the given id, if it exists
    return $this->merchants[$id];
  }

  public function get_category($id) { // Returns the category with the given id, if it exists
    return $this->categories[$id];
  }

  public function get_deal_type($id) { // Returns the deal_type with the given id, if it exists
    return $this->deal_types[$id];
  }

  public function get_brand($id) { // Returns the brand with the given id, if it exists
    return $this->brands[$id];
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Products API
  private function process_products_call($parsed_json) {

    // Load products
    $products = $parsed_json['results']['products']['product'];
    foreach ($products as $product) {
      $this->logger->info('Internalizing product with ID=' . (string) $product['id']);
      $this->internalize_product($product);
    }

    // Load deals
    $deals = $parsed_json['results']['deals']['deal'];
    foreach ($deals as $deal) {
      $this->logger->info('Internalizing deal with ID=' . (string) $deal['id']);
      $this->internalize_deal($deal);
    }

    // Load merchants
    $merchants = $parsed_json['resources']['merchants']['merchant'];
    foreach ($merchants as $merchant) {
      $this->logger->info('Internalizing merchant with ID=' . (string) $merchant['id']);
      $this->internalize_merchant($merchant);
    }
    
    // Load brands
    $brands = $parsed_json['resources']['brands']['brand'];
    foreach ($brands as $brand) {
      $this->logger->info('Internalizing brand with ID=' . (string) $brand['id']);
      $this->internalize_brand($brand);
    }

    // Load categories from both matches and context
    if (array_key_exists('matches', $parsed_json['resources']['categories'])) { // There's two places that categories exists. The 'matches' subsection, and the 'context' subsection
      $categories = $parsed_json['resources']['categories']['matches']['category']; // Load from matches, if it exists
      foreach ($categories as $category) {
	$this->logger->info('Internalizing category with ID=' . (string) $category['id']);
	$this->internalize_category($category);
      }
    }
    if (array_key_exists('context', $parsed_json['resources']['categories'])) { // There's two places that categories exists. The 'matches' subsection, and the 'context' subsection
      $categories = $parsed_json['resources']['categories']['context']['category']; // Load from context, if it exists
      foreach ($categories as $category) {
	$this->logger->info('Internalizing category with ID=' . (string) $category['id']);
	$this->internalize_category($category);
      }
    }

    // Load deal types
    $deal_types = $parsed_json['resources']['deal_types']['deal_type'];
    foreach ($deal_types as $deal_type) {
      $this->logger->info('Internalizing deal type with ID=' . (string) $deal_type['id']);
      $this->internalize_deal_type($deal_type);
    }
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Merchants API
  private function process_merchants_call($parsed_json) {
  }

  // Processes and internalizes the information present in a returned chunk of JSON from the Deals API
  private function process_deals_call($parsed_json) {
  }

  // Takes a chunk of decoded JSON representing a single Product (and any included offers)
  // Turns the JSON into a Product object, and appends it to the internal $products array, then turns any included Offer objects, and appends them to the internal $offers array
  // Returns true if success, false if parse/data error occurs
  private function internalize_product($product_json) {
    $tmp = new PsApiProduct($this);
    foreach ($product_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        case 'offers':
	  $offers_array = $value['offer'];
	  foreach ($offers_array as $offer) {
	    $this->internalize_offer($offer);
	    $this->offers[$offer['id']]->set_product($tmp); // Set the internalized offer's parent product to the currently internalized product
	    $tmp->add_offer($this->offers[$offer['id']]); // Add the offer we just internalized to the new (currently being internalized) product
	  }
	  break;
        default: // If there's no special case processing to do
	  if (is_numeric($value) or is_string($value)) { // Don't include any arrays or attributes that aren't just strings or numbers
	    $tmp->set_attr($attribute, $value);
	  }
      }
    }
    $this->products[$tmp->attr('id')] = $tmp;
    
    return true;
  }

  // Takes a chunk of decoded JSON representing a single Merchant
  // Turns the JSON into a Merchant object, and appends it to the internal $merchants array
  // Returns true if success, false if parse/data error occurs
  private function internalize_merchant($merchant_json) {
    $tmp = new PsApiMerchant($this);
    foreach ($merchant_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        default: // If there's no special case processing to do
	  $tmp->set_attr($attribute, $value);
      }
    }
    $this->merchants[$tmp->attr('id')] = $tmp;
    return true;
  }

  // Takes a chunk of decoded JSON representing a single Deal
  // Turns the JSON into a Deal object, and appends it to the internal $deals array
  // Returns true if success, false if parse/data error occurs
  private function internalize_deal($deal_json) {
    $tmp = new PsApiDeal($this);
    foreach ($deal_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        default: // If there's no special case processing to do
	  $tmp->set_attr($attribute, $value);
      }
    }
    $this->deals[$tmp->attr('id')] = $tmp;
    return true;
  }

  // Takes a chunk of decoded JSON representing a single Offer
  // Turns the JSON into a Offer object, and appends it to the internal $offers array
  // Returns true if success, false if parse/data error occurs
  private function internalize_offer($offer_json) {
    $tmp = new PsApiOffer($this);
    foreach ($offer_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        default: // If there's no special case processing to do, just toss it into the attributes
	  $tmp->set_attr($attribute, $value);
      }
    }
    $this->offers[$tmp->attr('id')] = $tmp;
    return true;
  }

  // Takes a chunk of decoded JSON representing a single Brand
  // Turns the JSON into a Brand object, and appends it to the internal $brands array
  // Returns true if success, false if parse/data error occurs
  private function internalize_brand($brand_json) {
    $tmp = new PsApiBrand($this);
    foreach ($brand_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        default: // If there's no special case processing to do, just toss it into the attributes
	  $tmp->set_attr($attribute, $value);
      }
    }
    $this->brands[$tmp->attr('id')] = $tmp;
    return true;
  }

  // Takes a chunk of decoded JSON representing a single Category
  // Turns the JSON into a Category object, and appends it to the internal $categories array
  // Returns true if success, false if parse/data error occurs
  private function internalize_category($category_json) {
    $tmp = new PsApiCategory($this);
    foreach ($category_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        default: // If there's no special case processing to do, just toss it into the attributes
	  $tmp->set_attr($attribute, $value);
      }
    }
    $this->categories[$tmp->attr('id')] = $tmp;
    return true;
  }

  // Takes a chunk of decoded JSON representing a single Deal Type
  // Turns the JSON into a DealType object, and appends it to the internal $deal_types array
  // Returns true if success, false if parse/data error occurs
  private function internalize_deal_type($deal_type_json) {
    $tmp = new PsApiDealType($this);
    foreach ($deal_type_json as $attribute=>$value) {
      switch ($attribute) { // This switch is meant to allow processing of special-case attributes
        default: // If there's no special case processing to do, just toss it into the attributes
	  $tmp->set_attr($attribute, $value);
      }
    }
    $this->deal_types[$tmp->attr('id')] = $tmp;
    return true;
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

  // Sets the given attribute to the given value. Should not be used by end-users of the PopShopsApi library
  public function set_attr($attribute, $value) {
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

  public function add_offer($offer) { // This is a special case method, due to the strange way that the API returns offers (nested inside of products)
    array_push($this->offers, $offer);
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
	return $this->reference->get_category($this->attr('category'));
      case 'brand':
	return $this->reference->get_brand($this->attr('brand'));
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
	  foreach ($this->reference->get_offers() as $offer) {
	    if ($offer->attr('merchant') == $this->attr('id')) {
	      array_push($this->offers, $offer);
	    }
	  }
	}
	return $this->offers;
      case 'deals':
	if (isset($this->deals)) {
	  return $this->deals;
	} else {
	  $this->deals = array();
	  foreach ($this->reference->get_deals() as $deal) {
	    if ($deal->attr('merchant') == $this->attr('id')) {
	      array_push($this->deals, $deal);
	    }
	  }
	}
	return $this->deals;
    }
  }
}

class PsApiDeal extends PsApiResource {
 
  public function __construct($reference) {
    parent::__construct($reference);
  }

  public function resource($key) {
  }
}

class PsApiOffer extends PsApiResource {

  private $product;

  public function __construct($reference) {
    parent::__construct($reference);
  }

  public function set_product($product) {
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
        return $this->reference->get_merchant($this->attr('merchant'));
    }
  } 
}


class PsApiBrand extends PsApiResource {
  
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
  }
}

class PsApiDealType extends PsApiResource {
   
  public function __construct($reference) {
    parent::__construct($reference);
  }
  
  public function resource($resource) {
  }
}

?>