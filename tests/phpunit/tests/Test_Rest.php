<?php

use CreativeMoods\PushPull\Rest;
use CreativeMoods\PushPull\PushPull;

class Test_Rest extends WP_UnitTestCase {
    
    /**
    * @var CreativeMoods\PushPull\PushPull|Mockery\Mock
    */
    protected $app;
    
    /**
    * @var CreativeMoods\PushPull\Rest
    */
    var $rest;
    
    /**
    * Create a mock of the PushPull class
    *
    * @return CreativeMoods\PushPull\PushPull|Mockery\Mock
    */
    private function create_mock() {
        return Mockery::mock( 'CreativeMoods\PushPull\PushPull' );
    }
    
    /**
    * Setup test
    *
    * @return void
    */
    public function setup (): void {
        parent::setup();
        
        $this->app = $this->create_mock();
        $this->rest = new Rest($this->app);
    }
    
    public function test_get_post_types () {
        $post_types = $this->rest->get_post_types();
        $arr = [
            'post' => 'post',
            'page' => 'page',
            'attachment' => 'attachment',
            'revision' => 'revision',
            'nav_menu_item' => 'nav_menu_item',
            'custom_css' => 'custom_css',
            'customize_changeset' => 'customize_changeset',
            'oembed_cache' => 'oembed_cache',
            'user_request' => 'user_request',
            'wp_block' => 'wp_block',
            'wp_template' => 'wp_template',
            'wp_template_part' => 'wp_template_part',
            'wp_global_styles' => 'wp_global_styles',
            'wp_navigation' => 'wp_navigation',
            'wp_font_family' => 'wp_font_family',
            'wp_font_face' => 'wp_font_face'
        ];
        $this->assertEqualsCanonicalizing($arr, $post_types);
    }
    
    public function test_get_local_repo () {
        // TODO only test what's coming back from the mock
        // The real app->persist()->local_tree() needs to be unit tested in Persist
        /*$local_repo = $this->rest->get_local_repo();
        $this->assertEquals($local_repo, "");*/
    }
}
