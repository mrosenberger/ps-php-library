<html>
    <body>
        <?php
	// This is meant as a demo of the url-mode feature. After the request uri, add as many param=value pairs as you wish
	// Each "param" should begin with the specified url-mode-prefix, in this case "psapi_", and be followed by an api option
	// For example, to search for the keyword "ipad", you would do:
	// GET localhost://simple-demo.php?psapi_keyword=ipad
	
        require 'ps-v3-library.php';
        $api = new PsApiCall('d1lg0my9c6y3j5iv5vkc6ayrd', 'dp4rtmme6tbhugpv6i59yiqmr',  true, 'psapi_', true);
        $api->get('products');
        print('<h3>Products</h3>');
        foreach ($api->resource('products') as $product) {
            print($product->attr('name') . '<br>');
        }
        print('<hr>');
        print('<a href="' . $api->prevPage() . '">Previous page</a>');
        print(' - ');
        print('<a href="' . $api->nextPage() . '">Next page</a>');
	print('<br>');
	print($api->resource('brands')[0]);
        ?>
    </body>
</html>