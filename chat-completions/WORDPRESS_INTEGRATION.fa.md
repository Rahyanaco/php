# راهنمای ادغام وردپرس - تولید تصویر Rahyana

این راهنما نحوه ادغام تولید تصویر Rahyana AI را در افزونه یا قالب وردپرس شما توضیح می‌دهد.

## فهرست مطالب

1. [نمای کلی](#نمای-کلی)
2. [پیش‌نیازها](#پیشنیازها)
3. [ادغام پایه](#ادغام-پایه)
4. [ایجاد افزونه وردپرس](#ایجاد-افزونه-وردپرس)
5. [ایجاد شورتکد وردپرس](#ایجاد-شورتکد-وردپرس)
6. [ایجاد بلاک وردپرس (گوتنبرگ)](#ایجاد-بلاک-وردپرس-گوتنبرگ)
7. [بهترین روش‌های امنیتی](#بهترین-روشهای-امنیتی)
8. [مدیریت خطا](#مدیریت-خطا)
9. [کش](#کش)
10. [افزونه نمونه کامل](#افزونه-نمونه-کامل)

## نمای کلی

این راهنما نحوه استفاده از Rahyana API برای تولید تصویر در وردپرس را نشان می‌دهد. می‌توانید آن را به صورت زیر ادغام کنید:
- یک افزونه مستقل
- یک شورتکد برای استفاده در پست‌ها/صفحات
- یک بلاک گوتنبرگ
- یک تابع قالب

## پیش‌نیازها

- وردپرس 5.0 یا بالاتر
- PHP 7.4 یا بالاتر
- افزونه cURL فعال
- کلید API Rahyana (از [rahyana.ir](https://rahyana.ir) دریافت کنید)

## ادغام پایه

### مرحله 1: ایجاد کلاس تولید تصویر

یک فایل `class-rahyana-image-generator.php` ایجاد کنید:

```php
<?php
/**
 * کلاس تولید تصویر Rahyana
 */
class Rahyana_Image_Generator {
    
    private $api_key;
    private $api_endpoint = 'https://rahyana.ir/api/v1';
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }
    
    /**
     * تولید تصویر با استفاده از Rahyana API
     * 
     * @param string $prompt متن تولید تصویر
     * @param string $model مدل استفاده (پیش‌فرض: google/gemini-3-pro-image-preview)
     * @return array|WP_Error آرایه با 'success', 'image_url', 'image_data' یا WP_Error در صورت خطا
     */
    public function generate_image($prompt, $model = 'google/gemini-3-pro-image-preview') {
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
                'درخواست API ناموفق: ' . $status_code,
                ['status' => $status_code, 'body' => $body]
            );
        }
        
        $result = json_decode($body, true);
        
        // استخراج تصویر از پاسخ
        $image_data_url = $this->extract_image_from_response($result);
        
        if (!$image_data_url) {
            return new WP_Error(
                'no_image',
                'هیچ تصویری در پاسخ API یافت نشد',
                ['response' => $result]
            );
        }
        
        // ذخیره تصویر در کتابخانه رسانه وردپرس
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
     * استخراج URL داده تصویر از پاسخ API
     */
    private function extract_image_from_response($response_data) {
        $image_data_url = null;
        
        // ابتدا providerResponse را بررسی کنید
        if (isset($response_data['providerResponse']['choices'][0]['message'])) {
            $message = $response_data['providerResponse']['choices'][0]['message'];
            $image_data_url = $this->extract_image_from_message($message);
        }
        
        // بازگشت به choices سطح بالا
        if (!$image_data_url && isset($response_data['choices'][0]['message'])) {
            $message = $response_data['choices'][0]['message'];
            $image_data_url = $this->extract_image_from_message($message);
        }
        
        return $image_data_url;
    }
    
    /**
     * استخراج تصویر از شیء پیام
     */
    private function extract_image_from_message($message) {
        if (!$message) {
            return null;
        }
        
        // بررسی آرایه images (فرمت Gemini)
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
        
        // بررسی فیلد content
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
     * ذخیره تصویر در کتابخانه رسانه وردپرس
     */
    private function save_image_to_media_library($data_url, $prompt) {
        // استخراج داده base64
        if (!preg_match('/^data:image\/([a-zA-Z0-9]+);base64,(.+)$/', $data_url, $matches)) {
            return new WP_Error('invalid_format', 'فرمت URL داده نامعتبر است');
        }
        
        $format = $matches[1];
        $image_data = base64_decode($matches[2]);
        
        if ($image_data === false) {
            return new WP_Error('decode_failed', 'رمزگشایی داده تصویر base64 ناموفق بود');
        }
        
        // ایجاد نام فایل
        $filename = sanitize_file_name('rahyana-' . substr(md5($prompt), 0, 8) . '-' . time() . '.' . $format);
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['path'] . '/' . $filename;
        
        // ذخیره فایل
        if (file_put_contents($file_path, $image_data) === false) {
            return new WP_Error('save_failed', 'ذخیره فایل تصویر ناموفق بود');
        }
        
        // آماده‌سازی داده ضمیمه
        $file_type = wp_check_filetype($filename, null);
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_text_field($prompt),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        
        // درج ضمیمه
        $attachment_id = wp_insert_attachment($attachment, $file_path);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_path);
            return $attachment_id;
        }
        
        // تولید متادیتای ضمیمه
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);
        
        return $attachment_id;
    }
}
```

## ایجاد افزونه وردپرس

### مرحله 1: ایجاد ساختار افزونه

یک پوشه `rahyana-image-generator` در `wp-content/plugins/` با ساختار زیر ایجاد کنید:

```
rahyana-image-generator/
├── rahyana-image-generator.php (فایل اصلی افزونه)
├── class-rahyana-image-generator.php
├── admin/
│   └── settings.php
└── includes/
    └── shortcode.php
```

### مرحله 2: فایل اصلی افزونه

فایل `rahyana-image-generator.php` را ایجاد کنید:

```php
<?php
/**
 * Plugin Name: Rahyana Image Generator
 * Plugin URI: https://github.com/your-repo/rahyana-image-generator
 * Description: تولید تصویر با استفاده از Rahyana AI API
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rahyana-image-generator
 */

// خروج در صورت دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// تعریف ثابت‌های افزونه
define('RAHYANA_IMAGE_GENERATOR_VERSION', '1.0.0');
define('RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAHYANA_IMAGE_GENERATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// شامل کردن فایل‌های مورد نیاز
require_once RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR . 'class-rahyana-image-generator.php';
require_once RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR . 'includes/shortcode.php';

// مقداردهی اولیه افزونه
add_action('plugins_loaded', 'rahyana_image_generator_init');

function rahyana_image_generator_init() {
    // بارگذاری تنظیمات ادمین در صورت بودن در ادمین
    if (is_admin()) {
        require_once RAHYANA_IMAGE_GENERATOR_PLUGIN_DIR . 'admin/settings.php';
    }
}

// ثبت هوک فعال‌سازی
register_activation_hook(__FILE__, 'rahyana_image_generator_activate');

function rahyana_image_generator_activate() {
    // تنظیم گزینه‌های پیش‌فرض
    add_option('rahyana_api_key', '');
    add_option('rahyana_default_model', 'google/gemini-3-pro-image-preview');
}
```

### مرحله 3: تنظیمات ادمین

فایل `admin/settings.php` را ایجاد کنید:

```php
<?php
// خروج در صورت دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// افزودن منوی تنظیمات
add_action('admin_menu', 'rahyana_image_generator_settings_menu');

function rahyana_image_generator_settings_menu() {
    add_options_page(
        'تنظیمات Rahyana Image Generator',
        'Rahyana Image Generator',
        'manage_options',
        'rahyana-image-generator',
        'rahyana_image_generator_settings_page'
    );
}

// ثبت تنظیمات
add_action('admin_init', 'rahyana_image_generator_register_settings');

function rahyana_image_generator_register_settings() {
    register_setting('rahyana_image_generator_settings', 'rahyana_api_key');
    register_setting('rahyana_image_generator_settings', 'rahyana_default_model');
}

// صفحه تنظیمات
function rahyana_image_generator_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_POST['submit'])) {
        check_admin_referer('rahyana_image_generator_settings');
        update_option('rahyana_api_key', sanitize_text_field($_POST['rahyana_api_key']));
        update_option('rahyana_default_model', sanitize_text_field($_POST['rahyana_default_model']));
        echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد!</p></div>';
    }
    
    $api_key = get_option('rahyana_api_key', '');
    $default_model = get_option('rahyana_default_model', 'google/gemini-3-pro-image-preview');
    ?>
    <div class="wrap">
        <h1>تنظیمات Rahyana Image Generator</h1>
        <form method="post" action="">
            <?php wp_nonce_field('rahyana_image_generator_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="rahyana_api_key">کلید API</label>
                    </th>
                    <td>
                        <input type="text" 
                               id="rahyana_api_key" 
                               name="rahyana_api_key" 
                               value="<?php echo esc_attr($api_key); ?>" 
                               class="regular-text" />
                        <p class="description">کلید API خود را از <a href="https://rahyana.ir" target="_blank">rahyana.ir</a> دریافت کنید</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rahyana_default_model">مدل پیش‌فرض</label>
                    </th>
                    <td>
                        <input type="text" 
                               id="rahyana_default_model" 
                               name="rahyana_default_model" 
                               value="<?php echo esc_attr($default_model); ?>" 
                               class="regular-text" />
                        <p class="description">مدل پیش‌فرض برای تولید تصویر (مثلاً: google/gemini-3-pro-image-preview)</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
```

## ایجاد شورتکد وردپرس

فایل `includes/shortcode.php` را ایجاد کنید:

```php
<?php
// خروج در صورت دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// ثبت شورتکد
add_shortcode('rahyana_image', 'rahyana_image_generator_shortcode');

function rahyana_image_generator_shortcode($atts) {
    $atts = shortcode_atts([
        'prompt' => 'a beautiful landscape',
        'model' => get_option('rahyana_default_model', 'google/gemini-3-pro-image-preview'),
        'size' => 'medium',
        'class' => 'rahyana-generated-image'
    ], $atts, 'rahyana_image');
    
    $api_key = get_option('rahyana_api_key');
    if (empty($api_key)) {
        return '<p class="rahyana-error">خطا: کلید API پیکربندی نشده است. لطفاً آن را در Settings > Rahyana Image Generator پیکربندی کنید.</p>';
    }
    
    $generator = new Rahyana_Image_Generator($api_key);
    $result = $generator->generate_image($atts['prompt'], $atts['model']);
    
    if (is_wp_error($result)) {
        return '<p class="rahyana-error">خطا: ' . esc_html($result->get_error_message()) . '</p>';
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

### استفاده

شورتکد را به هر پست یا صفحه اضافه کنید:

```
[rahyana_image prompt="a dog in a city" size="large"]
```

## ایجاد بلاک وردپرس (گوتنبرگ)

برای ادغام بلاک گوتنبرگ، باید یک کامپوننت React ایجاد کنید. در اینجا یک نمونه پایه آورده شده است:

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
                    setError(data.message || 'تولید تصویر ناموفق بود');
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
                    label="متن تصویر"
                    value={attributes.prompt}
                    onChange={(value) => setAttributes({ prompt: value })}
                />
                <Button
                    isPrimary
                    onClick={generateImage}
                    disabled={loading}
                >
                    {loading ? 'در حال تولید...' : 'تولید تصویر'}
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

## بهترین روش‌های امنیتی

1. **هرگز کلیدهای API را در کد frontend افشا نکنید**
   - کلیدهای API را در گزینه‌های وردپرس (پایگاه داده) ذخیره کنید
   - از nonceهای وردپرس برای درخواست‌های AJAX استفاده کنید
   - مجوزهای کاربر را اعتبارسنجی کنید

2. **ورودی‌ها را sanitize و اعتبارسنجی کنید**
   ```php
   $prompt = sanitize_text_field($_POST['prompt']);
   $model = sanitize_text_field($_POST['model']);
   ```

3. **از WordPress HTTP API استفاده کنید**
   - از `wp_remote_post()` به جای cURL مستقیم استفاده کنید
   - وردپرس به طور خودکار تأیید SSL را مدیریت می‌کند

4. **محدودسازی نرخ**
   - محدودسازی نرخ را برای جلوگیری از سوء استفاده پیاده‌سازی کنید
   - تصاویر تولید شده را کش کنید

## مدیریت خطا

همیشه خطاها را به صورت مناسب مدیریت کنید:

```php
$result = $generator->generate_image($prompt);

if (is_wp_error($result)) {
    error_log('خطای تولید تصویر Rahyana: ' . $result->get_error_message());
    // نمایش پیام خطای کاربرپسند
    return '<p>متأسفانه، تولید تصویر ناموفق بود. لطفاً بعداً دوباره تلاش کنید.</p>';
}
```

## کش

تصاویر تولید شده را برای کاهش درخواست‌های API کش کنید:

```php
// ابتدا کش را بررسی کنید
$cache_key = 'rahyana_image_' . md5($prompt);
$cached_image = get_transient($cache_key);

if ($cached_image !== false) {
    return $cached_image;
}

// تولید تصویر جدید
$result = $generator->generate_image($prompt);

// کش برای 24 ساعت
if ($result['success']) {
    set_transient($cache_key, $result['image_url'], DAY_IN_SECONDS);
}

return $result;
```

## افزونه نمونه کامل

برای یک افزونه کامل و آماده تولید، فایل‌های نمونه در این پوشه را ببینید. افزونه شامل موارد زیر است:

- ✅ صفحه تنظیمات ادمین
- ✅ پشتیبانی از شورتکد
- ✅ مدیریت خطا
- ✅ کش تصویر
- ✅ بهترین روش‌های امنیتی
- ✅ استانداردهای کدنویسی وردپرس

## منابع اضافی

- [مستندات Rahyana API](https://rahyana.ir/docs)
- [راهنمای افزونه وردپرس](https://developer.wordpress.org/plugins/)
- [WordPress HTTP API](https://developer.wordpress.org/plugins/http-api/)

## پشتیبانی

برای مشکلات یا سوالات:
- GitHub Issues: [آدرس مخزن شما]
- ایمیل: support@yourdomain.com
- مستندات: [آدرس مستندات شما]

