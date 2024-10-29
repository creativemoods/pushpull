<?php

require('PushPull.php');
use CreativeMoods\PushPull\PushPull;
use CreativeMoods\PushPull\hooks\RealMediaLibrary;

class Test_RealMediaLibrary extends WP_UnitTestCase {
    
    /**
    * @var CreativeMoods\PushPull\PushPull
    */
    protected $app;
    
    /**
    * @var CreativeMoods\PushPull\hooks\RealMediaLibrary
    */
    var $rml;
    
    /**
    * Mock the wp_attachment_folder function
    *
    * @return void
    */
    private function mock_wp_attachment_folder() {
        if (!function_exists('wp_attachment_folder')) {
            function wp_attachment_folder($attachment_id) {
                return 'mocked_folder';
            }
        }
    }

    /**
    * Mock the wp_rml_get_by_id function
    *
    * @return void
    */
    private function mock_wp_rml_get_by_id() {
        if (!function_exists('wp_rml_get_by_id')) {
            function wp_rml_get_by_id($id) {
                return (object) [
                    'id' => $id,
                    'name' => 'mocked_folder',
                    'getName' => function() {
                        return 'mocked_folder';
                    }
                ];
            }
        }
    }

    /**
    * Mock the is_rml_folder function
    *
    * @return void
    */
    private function mock_is_rml_folder() {
        if (!function_exists('is_rml_folder')) {
            function is_rml_folder($id) {
                return true;
            }
        }
    }

    /**
    * Setup test
    *
    * @return void
    */
    public function setup (): void {
        parent::setup();
        
        $this->app = new PushPull();
        //fwrite(STDERR, print_r($this->app, TRUE));
        $this->app->boot();
        $this->app->write_log("grotest");
        $this->rml = new RealMediaLibrary($this->app);
        $this->mock_wp_attachment_folder();
        $this->mock_wp_rml_get_by_id();
        $this->mock_is_rml_folder();
    }

    public function test_export () {
        
        $post = $this->factory()->post->create_and_get([
            'post_title' => 'Test Post',
            'post_type' => 'attachment',
        ]);
        $data = $this->rml->export([], $post);
        $this->assertEmpty($data);
    }
}
