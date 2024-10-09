<?php

class Test_PushPull extends WP_UnitTestCase {

    public function test_constants () {
        $this->assertSame( 'pushpull', WPSP_NAME );

        $url = str_replace( 'tests/phpunit/tests/', '',
                trailingslashit( plugin_dir_url( __FILE__ ) ) );
        $this->assertSame( $url, WPSP_URL );
    }
}
