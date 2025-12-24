# Rahyana API - PHP Examples

> ⚠️ **Note**: Not all examples are tested yet. Please use with caution and report any issues.

This directory contains PHP examples for using the Rahyana AI API. These examples demonstrate how to integrate Rahyana's AI capabilities into PHP applications, including WordPress plugins.

## 📁 Directory Structure

```
php/
├── chat-completions/
│   ├── image-generation.php      # Image generation example
│   ├── image-editing.php         # Image editing/modification example
│   ├── WORDPRESS_INTEGRATION.md   # WordPress integration guide (English)
│   └── WORDPRESS_INTEGRATION.fa.md # WordPress integration guide (Farsi)
├── completions/
└── models/
```

## 🚀 Quick Start

### Prerequisites

- PHP 7.4 or higher
- cURL extension enabled
- GD extension enabled (required for image editing script)
- A Rahyana API key (get one at [rahyana.ir](https://rahyana.ir))

### Basic Usage

1. **Set your API key** (via environment variable or edit the script):
   ```bash
   export API_KEY_OVERRIDE="your_api_key_here"
   ```

2. **Run the image generation example**:
   ```bash
   php chat-completions/image-generation.php
   ```

3. **Generated images** will be saved to `chat-completions/generated-images/` directory

## 📚 Examples

### Image Generation

**File**: `chat-completions/image-generation.php`

Generate images using the Rahyana API. This example:
- Connects to the Rahyana API
- Generates an image based on a text prompt
- Saves the generated image to a local directory

**Usage**:
```bash
# Using environment variable for API key
export API_KEY_OVERRIDE="your_api_key_here"
php chat-completions/image-generation.php

# Or set output directory
export OUTPUT_DIR="/path/to/output"
php chat-completions/image-generation.php
```

**Configuration**:
- `API_KEY_OVERRIDE`: Your Rahyana API key (environment variable)
- `OUTPUT_DIR`: Directory to save images (defaults to `./generated-images`)
- Model: `google/gemini-3-pro-image-preview` (configurable in script)
- Prompt: `"a dog in a city"` (configurable in script)

### Image Editing

**File**: `chat-completions/image-editing.php`

Edit and modify existing images using the Rahyana API. This example:
- Accepts an image file as input
- Sends the image to the API with modification instructions
- Automatically resizes large images to meet API size limits
- Saves the edited/modified image to a local directory

**Usage**:
```bash
# Basic usage
export API_KEY_OVERRIDE="your_api_key_here"
php chat-completions/image-editing.php input.jpg "make it look like a painting"

# More examples
php chat-completions/image-editing.php photo.png "add a sunset in the background"
php chat-completions/image-editing.php image.jpg "remove the background"
php chat-completions/image-editing.php picture.png "enhance colors and contrast"

# Set custom output directory
export OUTPUT_DIR="/path/to/output"
php chat-completions/image-editing.php input.jpg "make it vintage style"
```

**Features**:
- Automatic image resizing for large files (API size limits)
- Supports JPEG, PNG, GIF, WebP formats
- Maintains aspect ratio during resizing
- Base64 encoding for API transmission
- Comprehensive error handling

**Configuration**:
- `API_KEY_OVERRIDE`: Your Rahyana API key (environment variable)
- `OUTPUT_DIR`: Directory to save edited images (defaults to `./edited-images`)
- Model: `google/gemini-3-pro-image-preview` (configurable in script)
- Modification prompt: Provided as command-line argument

## 🔌 WordPress Integration

**Files**: 
- `chat-completions/WORDPRESS_INTEGRATION.md` (English)
- `chat-completions/WORDPRESS_INTEGRATION.fa.md` (Farsi/Persian)

Complete guide for integrating Rahyana image generation into WordPress:

- ✅ Standalone plugin creation
- ✅ Shortcode implementation
- ✅ Gutenberg block development
- ✅ Security best practices
- ✅ Error handling
- ✅ Caching strategies

### Quick WordPress Integration

1. **Create a plugin directory** in `wp-content/plugins/`
2. **Copy the integration code** from `WORDPRESS_INTEGRATION.md`
3. **Configure your API key** in WordPress Settings
4. **Use the shortcode** in posts/pages:
   ```
   [rahyana_image prompt="a dog in a city" size="large"]
   ```

## 🛠️ Features

### Image Generation Script

- ✅ Full API integration with Rahyana
- ✅ Multiple response format handling
- ✅ Base64 image extraction and decoding
- ✅ Automatic directory creation
- ✅ Comprehensive error handling
- ✅ Command-line interface

### Image Editing Script

- ✅ Accepts image files as input
- ✅ Automatic image resizing for large files
- ✅ Multiple image format support (JPEG, PNG, GIF, WebP)
- ✅ Base64 encoding for API transmission
- ✅ Image modification with custom prompts
- ✅ Preserves aspect ratio during resizing
- ✅ Temporary file cleanup

### WordPress Integration

- ✅ Admin settings page
- ✅ Shortcode support
- ✅ Media library integration
- ✅ Security best practices
- ✅ Caching support
- ✅ Error handling

## 📖 API Reference

### Image Generation Request

```php
$data = [
    'model' => 'google/gemini-3-pro-image-preview',
    'messages' => [
        [
            'role' => 'user',
            'content' => 'your prompt here'
        ]
    ],
    'modalities' => ['image', 'text']
];
```

### Response Structure

The API returns images in various formats. The script handles:
- `providerResponse.choices[0].message.images[0].image_url.url`
- `choices[0].message.content` (array or string)
- Direct data URLs

## 🔒 Security

- **Never commit API keys** to version control
- Use environment variables for API keys
- Sanitize all user inputs
- Validate API responses
- Use WordPress nonces for AJAX requests

## 🐛 Troubleshooting

### Common Issues

1. **"API key not configured"**
   - Set `API_KEY_OVERRIDE` environment variable
   - Or update the script with your API key

2. **"Failed to create directory"**
   - Check directory permissions
   - Ensure PHP has write access

3. **"No image found in response"**
   - Check API response structure
   - Verify model supports image generation
   - Check API key permissions

4. **WordPress: "API key not configured"**
   - Go to Settings > Rahyana Image Generator
   - Enter your API key

## 📝 Code Examples

### Basic Image Generation

```php
<?php
require_once 'class-rahyana-image-generator.php';

$api_key = 'your_api_key_here';
$generator = new Rahyana_Image_Generator($api_key);

$result = $generator->generate_image('a dog in a city');

if (is_wp_error($result)) {
    echo 'Error: ' . $result->get_error_message();
} else {
    echo 'Image URL: ' . $result['image_url'];
}
```

### WordPress Shortcode

```php
[rahyana_image prompt="a beautiful sunset over mountains" size="large" class="my-custom-class"]
```

## 🔗 Related Resources

- [Rahyana API Documentation](https://rahyana.ir/docs)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [PHP cURL Documentation](https://www.php.net/manual/en/book.curl.php)

## 📄 License

These examples are provided as-is for educational and integration purposes.

## 🤝 Contributing

Contributions are welcome! Please:
1. Follow PHP coding standards (PSR-12)
2. Include error handling
3. Add comments for complex logic
4. Test with real API keys (safely)

## 📧 Support

For issues or questions:
- Check the [WordPress Integration Guide](chat-completions/WORDPRESS_INTEGRATION.md)
- Review API documentation at [rahyana.ir/docs](https://rahyana.ir/docs)
- Open an issue on GitHub

