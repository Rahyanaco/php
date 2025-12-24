# WordPress Integration Guide - Rahyana Image Generation

This guide explains how to integrate Rahyana AI image generation into your WordPress plugin or theme.

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Basic Integration](#basic-integration)
4. [Creating a WordPress Plugin](#creating-a-wordpress-plugin)
5. [Creating a WordPress Shortcode](#creating-a-wordpress-shortcode)
6. [Creating a WordPress Block (Gutenberg)](#creating-a-wordpress-block-gutenberg)
7. [Security Best Practices](#security-best-practices)
8. [Error Handling](#error-handling)
9. [Caching](#caching)
10. [Complete Example Plugin](#complete-example-plugin)

## Overview

This guide shows you how to use the Rahyana API to generate images in WordPress. You can integrate it as:
- A standalone plugin
- A shortcode for use in posts/pages
- A Gutenberg block
- A theme function

## Prerequisites

- WordPress 5.0 or higher
- PHP 7.4 or higher
- cURL extension enabled
- A Rahyana API key (get one at [rahyana.ir](https://rahyana.ir))

## Basic Integration

### Step 1: Create the Image Generation Class

Create a file `class-rahyana-image-generator.php`:

```php
<?php
/**
 * Rahyana Image Generator Class
 */
class Rahyana_Image_Generator {
    
    private $api_key;
    private $api_endpoint = 'https://rahyana.ir/api/v1';
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }
    
    /**
     * Generate an image using Rahyana API
     * 
     * @param string $prompt The image generation prompt
     * @param string $model The model to use (default: google/gemini-2.5-flash-image)
     * @return array|WP_Error Returns array with 'success', 'image_url', 'image_data' or WP_Error on failure
     */
    public function generate_image($prompt, $model = 'google/gemini-2.5-flash-image') {
        $url = $this->api_endpoint . '/chat/completions';
        
        $data = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'modalities' => ['image', 'text']
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
            'timeout' => 60,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            return new WP_Error(
                'api_error',
                'API request failed: ' . $status_code,
                ['status' => $status_code, 'body' => $body]
            );
        }
        
        $result = json_decode($body, true);
        
        // Extract image from response
        $image_data_url = $this->extract_image_from_response($result);
        
        if (!$image_data_url) {
            return new WP_Error(
                'no_image',
                'No image found in API response',
                ['response' => $result]
            );
        }
        
        // Save image to WordPress media library
        $attachment_id = $this->save_image_to_media_library($image_data_url, $prompt);
        
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        
        $image_url = wp_get_attachment_url($attachment_id);
        
        return [
            'success' => true,
            'attachment_id' => $attachment_id,
            'image_url' => $image_url,
            'image_data' => $image_data_url
        ];
    }
    
    /**
     * Extract image data URL from API response
     */
    private function extract_image_from_response($response_data) {
        $image_data_url = null;
        
        // Check providerResponse first
        if (isset($response_data['providerResponse']['choices'][0]['message'])) {
            $message = $response_data['providerResponse']['choices'][0]['message'];
            $image_data_url = $this->extract_image_from_message($message);
        }
        
        // Fallback to top-level choices
        if (!$image_data_url && isset($response_data['choices'][0]['message'])) {
            $message = $response_data['choices'][0]['message'];
            $image_data_url = $this->extract_image_from_message($message);
        }
        
        return $image_data_url;
    }
    
    /**
     * Extract image from message object
     */
    private function extract_image_from_message($message) {
        if (!$message) {
            return null;
        }
        
        // Check for images array (Gemini format)
        if (isset($message['images']) && is_array($message['images']) && count($message['images']) > 0) {
            $imageItem = $message['images'][0];
            if (isset($imageItem['type']) && 
                $imageItem['type'] === 'image_url' && 
                isset($imageItem['image_url']['url'])) {
                $url = $imageItem['image_url']['url'];
                if (is_string($url) && strpos($url, 'data:image/') === 0) {
                    return $url;
                }
            }
        }
        
        // Check content field
        if (isset($message['content'])) {
            if (is_array($message['content'])) {
                foreach ($message['content'] as $item) {
                    if (!is_array($item)) continue;
                    
                    if (isset($item['type']) && 
                        $item['type'] === 'image_url' && 
                        isset($item['image_url']['url'])) {
                        $url = $item['image_url']['url'];
                        if (is_string($url) && strpos($url, 'data:image/') === 0) {
                            return $url;
                        }
                    }
                }
            } elseif (is_string($message['content']) && strpos($message['content'], 'data:image/') === 0) {
                return $message['content'];
            }
        }
        
        return null;
    }
    
    /**
     * Save image to WordPress media library
     */
    private function save_image_to_media_library($data_url, $prompt) {
        // Extract base64 data
        if (!preg_match('/^data:image\/([a-zA-Z0-9]+);base64,(.+)$/', $data_url, $matches)) {
            return new WP_Error('invalid_format', 'Invalid data URL format');
        }
        
        $format = $matches[1];
        $image_data = base64_decode($matches[2]);
        
        if ($image_data === false) {
            return new WP_Error('decode_failed', 'Failed to decode base64 image data');
        }
        
        // Create filename
        $filename = sanitize_file_name('rahyana-' . substr(md5($prompt), 0, 8) . '-' . time() . '.' . $format);
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['path'] . '/' . $filename;
        
        // Save file
        if (file_put_contents($file_path, $image_data) === false) {
            return new WP_Error('save_failed', 'Failed to save image file');
        }
        
        // Prepare attachment data
        $file_type = wp_check_filetype($filename, null);
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_text_field($prompt),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment, $file_path);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_path);
            return $attachment_id;
        }
        
        // Generate attachment metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);
        
        return $attachment_id;
    }
}
```

## Creating a WordPress Plugin

### Step 1: Create Plugin Structure

Create a directory `rahyana-image-generator` in `wp-content/plugins/` with the following structure:

```
rahyana-image-generator/
├── rahyana-image-generator.php (main plugin file)
├── class-rahyana-image-generator.php
├── admin/
│   └── settings.php
└── includes/
    └── shortcode.php
```

### Step 2: Main Plugin File

Create `rahyana-image-generator.php`:

```php
<?php
/**
 * Plugin Name: Rahyana Image Generator
 * Plugin URI: https://github.com/your-repo/rahyana-image-generator
 * Description: Generate images using Rahyana AI API
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rahyana-image-generator
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RAHYANA_IMAGE_GENERATOR_VERSION', '1.0.0');
define('RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAHYANA_IMAGE_GENERATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR . 'class-rahyana-image-generator.php';
require_once RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR . 'includes/shortcode.php';

// Initialize plugin
add_action('plugins_loaded', 'rahyana_image_generator_init');

function rahyana_image_generator_init() {
    // Load admin settings if in admin
    if (is_admin()) {
        require_once RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR . 'admin/settings.php';
    }
}

// Register activation hook
register_activation_hook(__FILE__, 'rahyana_image_generator_activate');

function rahyana_image_generator_activate() {
    // Set default options
    add_option('rahyana_api_key', '');
    add_option('rahyana_default_model', 'google/gemini-2.5-flash-image');
}
```

### Step 3: Admin Settings

Create `admin/settings.php`:

```php
<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add settings menu
add_action('admin_menu', 'rahyana_image_generator_settings_menu');

function rahyana_image_generator_settings_menu() {
    add_options_page(
        'Rahyana Image Generator Settings',
        'Rahyana Image Generator',
        'manage_options',
        'rahyana-image-generator',
        'rahyana_image_generator_settings_page'
    );
}

// Register settings
add_action('admin_init', 'rahyana_image_generator_register_settings');

function rahyana_image_generator_register_settings() {
    register_setting('rahyana_image_generator_settings', 'rahyana_api_key');
    register_setting('rahyana_image_generator_settings', 'rahyana_default_model');
}

// Settings page
function rahyana_image_generator_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_POST['submit'])) {
        check_admin_referer('rahyana_image_generator_settings');
        update_option('rahyana_api_key', sanitize_text_field($_POST['rahyana_api_key']));
        update_option('rahyana_default_model', sanitize_text_field($_POST['rahyana_default_model']));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $api_key = get_option('rahyana_api_key', '');
    $default_model = get_option('rahyana_default_model', 'google/gemini-2.5-flash-image');
    ?>
    <div class="wrap">
        <h1>Rahyana Image Generator Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('rahyana_image_generator_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="rahyana_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text" 
                               id="rahyana_api_key" 
                               name="rahyana_api_key" 
                               value="<?php echo esc_attr($api_key); ?>" 
                               class="regular-text" />
                        <p class="description">Get your API key from <a href="https://rahyana.ir" target="_blank">rahyana.ir</a></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rahyana_default_model">Default Model</label>
                    </th>
                    <td>
                        <input type="text" 
                               id="rahyana_default_model" 
                               name="rahyana_default_model" 
                               value="<?php echo esc_attr($default_model); ?>" 
                               class="regular-text" />
                        <p class="description">Default model for image generation (e.g., google/gemini-2.5-flash-image)</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
```

## Creating a WordPress Shortcode

Create `includes/shortcode.php`:

```php
<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Register shortcode
add_shortcode('rahyana_image', 'rahyana_image_generator_shortcode');

function rahyana_image_generator_shortcode($atts) {
    $atts = shortcode_atts([
        'prompt' => 'a beautiful landscape',
        'model' => get_option('rahyana_default_model', 'google/gemini-2.5-flash-image'),
        'size' => 'medium',
        'class' => 'rahyana-generated-image'
    ], $atts, 'rahyana_image');
    
    $api_key = get_option('rahyana_api_key');
    if (empty($api_key)) {
        return '<p class="rahyana-error">Error: API key not configured. Please configure it in Settings > Rahyana Image Generator.</p>';
    }
    
    $generator = new Rahyana_Image_Generator($api_key);
    $result = $generator->generate_image($atts['prompt'], $atts['model']);
    
    if (is_wp_error($result)) {
        return '<p class="rahyana-error">Error: ' . esc_html($result->get_error_message()) . '</p>';
    }
    
    $size_class = 'size-' . esc_attr($atts['size']);
    $image_class = esc_attr($atts['class']) . ' ' . $size_class;
    
    return sprintf(
        '<img src="%s" alt="%s" class="%s" />',
        esc_url($result['image_url']),
        esc_attr($atts['prompt']),
        $image_class
    );
}
```

### Usage

Add the shortcode to any post or page:

```
[rahyana_image prompt="a dog in a city" size="large"]
```

## Creating a WordPress Block (Gutenberg)

For Gutenberg block integration, you'll need to create a React component. Here's a basic example:

```javascript
// block.js
import { registerBlockType } from '@wordpress/blocks';
import { TextControl, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';

registerBlockType('rahyana/image-generator', {
    title: 'Rahyana Image Generator',
    icon: 'format-image',
    category: 'media',
    attributes: {
        prompt: {
            type: 'string',
            default: 'a beautiful landscape'
        },
        imageUrl: {
            type: 'string',
            default: ''
        }
    },
    edit: ({ attributes, setAttributes }) => {
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState('');
        
        const generateImage = async () => {
            setLoading(true);
            setError('');
            
            try {
                const response = await fetch('/wp-json/rahyana/v1/generate-image', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': wpApiSettings.nonce
                    },
                    body: JSON.stringify({
                        prompt: attributes.prompt
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    setAttributes({ imageUrl: data.image_url });
                } else {
                    setError(data.message || 'Failed to generate image');
                }
            } catch (err) {
                setError(err.message);
            } finally {
                setLoading(false);
            }
        };
        
        return (
            <div className="rahyana-image-generator-block">
                <TextControl
                    label="Image Prompt"
                    value={attributes.prompt}
                    onChange={(value) => setAttributes({ prompt: value })}
                />
                <Button
                    isPrimary
                    onClick={generateImage}
                    disabled={loading}
                >
                    {loading ? 'Generating...' : 'Generate Image'}
                </Button>
                {error && <p className="error">{error}</p>}
                {attributes.imageUrl && (
                    <img src={attributes.imageUrl} alt={attributes.prompt} />
                )}
            </div>
        );
    },
    save: ({ attributes }) => {
        if (!attributes.imageUrl) {
            return null;
        }
        return <img src={attributes.imageUrl} alt={attributes.prompt} />;
    }
});
```

## Security Best Practices

1. **Never expose API keys in frontend code**
   - Store API keys in WordPress options (database)
   - Use WordPress nonces for AJAX requests
   - Validate user permissions

2. **Sanitize and validate inputs**
   ```php
   $prompt = sanitize_text_field($_POST['prompt']);
   $model = sanitize_text_field($_POST['model']);
   ```

3. **Use WordPress HTTP API**
   - Use `wp_remote_post()` instead of direct cURL
   - WordPress handles SSL verification automatically

4. **Rate limiting**
   - Implement rate limiting to prevent abuse
   - Cache generated images

## Error Handling

Always handle errors gracefully:

```php
$result = $generator->generate_image($prompt);

if (is_wp_error($result)) {
    error_log('Rahyana Image Generation Error: ' . $result->get_error_message());
    // Show user-friendly error message
    return '<p>Sorry, image generation failed. Please try again later.</p>';
}
```

## Caching

Cache generated images to reduce API calls:

```php
// Check cache first
$cache_key = 'rahyana_image_' . md5($prompt);
$cached_image = get_transient($cache_key);

if ($cached_image !== false) {
    return $cached_image;
}

// Generate new image
$result = $generator->generate_image($prompt);

// Cache for 24 hours
if ($result['success']) {
    set_transient($cache_key, $result['image_url'], DAY_IN_SECONDS);
}

return $result;
```

## Complete Example Plugin

For a complete, production-ready plugin, see the example files in this directory. The plugin includes:

- ✅ Admin settings page
- ✅ Shortcode support
- ✅ Error handling
- ✅ Image caching
- ✅ Security best practices
- ✅ WordPress coding standards

## Additional Resources

- [Rahyana API Documentation](https://rahyana.ir/docs)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress HTTP API](https://developer.wordpress.org/plugins/http-api/)

## Support

For issues or questions:
- GitHub Issues: [Your Repository URL]
- Email: support@yourdomain.com
- Documentation: [Your Documentation URL]

