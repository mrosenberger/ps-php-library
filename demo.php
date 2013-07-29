<?php
  require('ps-v3-library.php');                                               // Include the library
  $api = new PsApiCall('d1lg0my9c6y3j5iv5vkc6ayrd',
                       'dp4rtmme6tbhugpv6i59yiqmr'); 
  $api->get('products');                                                      // Note that no params are passed
  foreach ($api->getProducts() as $product) {                                 // Iterate through products
    print($product->getName() . '<br/>');                                     // Print the name of each
  }
  // Create a link to this page, but with the 'page' parameter incremented:
  print('<a href="' . $api->nextPage() . '">Next page</a>');      
?>