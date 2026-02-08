<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NC_OneSignal_Integration {

    public function __construct() {
        // Automation removed per user request. 
        // Logic relies entirely on manual configuration in OneSignal Plugin settings.
        // Instructions are provided in the Metabox.

        // Filter removed. We now handle redirection via template_redirect in NC_Post_Type.
    }
}
