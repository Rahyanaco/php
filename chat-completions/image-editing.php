<?php
/**
 * Rahyana API - Image Editing Example
 * 
 * This example demonstrates how to send an image to the Rahyana API for editing/modification
 * and save the modified result. The script accepts an image file and a modification prompt.
 * 
 * Usage:
 *   php image-editing.php <image_path> [modification_prompt]
 * 
 * Examples:
 *   php image-editing.php input.jpg "make it look like a painting"
 *   php image-editing.php input.png "add a sunset in the background"
 *   php image-editing.php input.jpg "remove the background"
 * 
 * Environment Variables:
 *   API_KEY_OVERRIDE - Your Rahyana API key (optional, defaults to YOUR_API_KEY_HERE)
 *   OUTPUT_DIR - Directory to save images (optional, defaults to ./edited-images)
 */

// Configuration
$API_KEY = getenv('API_KEY_OVERRIDE') ?: 'rhy_S_PJPvCubJc7jv2QVBCJBWqGCD8lvRUSZls3J8hC7dA';
$API_ENDPOINT = 'localhost:3000';
$MODEL = 'google/gemini-3-pro-image-preview';
$OUTPUT_DIR = getenv('OUTPUT_DIR') ?: __DIR__ . '/edited-images';

// Default modification prompt if not provided
$DEFAULT_PROMPT = 'enhance this image and make it more vibrant';

/**
 * Get MIME type from file extension
 */
function getMimeType($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp'
    ];
    return $mimeTypes[$extension] ?? 'image/jpeg';
}

/**
 * Resize image if it's too large (max 500KB recommended for API to account for base64 encoding)
 */
function resizeImageIfNeeded($imagePath, $maxSizeBytes = 500000) {
    $fileSize = filesize($imagePath);
    
    if ($fileSize <= $maxSizeBytes) {
        return $imagePath; // No resize needed
    }
    
    echo "⚠️  Warning: Image is large (" . number_format($fileSize) . " bytes). Resizing...\n";
    
    // Check if GD extension is available
    if (!extension_loaded('gd')) {
        throw new Exception("GD extension is required for image resizing. Please install php-gd extension.");
    }
    
    $mimeType = getMimeType($imagePath);
    $imageInfo = getimagesize($imagePath);
    
    if ($imageInfo === false) {
        throw new Exception("Failed to get image dimensions");
    }
    
    list($width, $height) = $imageInfo;
    
    // Calculate new dimensions (maintain aspect ratio, target ~400KB to account for base64 overhead)
    $targetSize = 400000;
    $ratio = sqrt($targetSize / $fileSize);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    
    // Ensure minimum dimensions but keep reasonable
    if ($newWidth < 512) $newWidth = 512;
    if ($newHeight < 512) $newHeight = 512;
    
    // Also cap maximum dimensions
    if ($newWidth > 1024) $newWidth = 1024;
    if ($newHeight > 1024) $newHeight = 1024;
    
    // Create image resource based on type
    switch ($mimeType) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($imagePath);
            break;
        case 'image/png':
            $source = imagecreatefrompng($imagePath);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($imagePath);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($imagePath);
            break;
        default:
            throw new Exception("Unsupported image type: $mimeType");
    }
    
    if ($source === false) {
        throw new Exception("Failed to create image resource");
    }
    
    // Create resized image
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG
    if ($mimeType === 'image/png') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
    }
    
    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Save to temporary file
    $tempPath = sys_get_temp_dir() . '/rahyana_resized_' . basename($imagePath);
    
    // Always save as JPEG for better compression (unless original is PNG with transparency)
    $saveAsJpeg = ($mimeType !== 'image/png' || !imageistruecolor($source));
    
    if ($saveAsJpeg) {
        // Convert to JPEG for better compression
        $tempPath = sys_get_temp_dir() . '/rahyana_resized_' . pathinfo($imagePath, PATHINFO_FILENAME) . '.jpg';
        imagejpeg($resized, $tempPath, 80); // 80% quality for good balance
    } else {
        // Keep PNG but with higher compression
        imagepng($resized, $tempPath, 5); // Higher compression (0-9, lower = more compression)
    }
    
    imagedestroy($source);
    imagedestroy($resized);
    
    $newSize = filesize($tempPath);
    echo "Resized to: {$newWidth}x{$newHeight} (" . number_format($newSize) . " bytes)\n";
    
    return $tempPath;
}

/**
 * Encode image file to base64
 */
function encodeImageToBase64($imagePath) {
    if (!file_exists($imagePath)) {
        throw new Exception("Image file not found: $imagePath");
    }
    
    if (!is_readable($imagePath)) {
        throw new Exception("Image file is not readable: $imagePath");
    }
    
    $imageData = file_get_contents($imagePath);
    if ($imageData === false) {
        throw new Exception("Failed to read image file: $imagePath");
    }
    
    return base64_encode($imageData);
}

/**
 * Ensure output directory exists
 */
function ensureDirectoryExists($dirPath) {
    if (!is_dir($dirPath)) {
        if (!mkdir($dirPath, 0755, true)) {
            throw new Exception("Failed to create directory: $dirPath");
        }
        echo "Created directory: $dirPath\n";
    }
}

/**
 * Extract base64 data from data URL
 */
function extractBase64FromDataUrl($dataUrl) {
    if (preg_match('/^data:image\/([a-zA-Z0-9]+);base64,(.+)$/', $dataUrl, $matches)) {
        return [
            'format' => $matches[1],
            'data' => $matches[2]
        ];
    }
    return null;
}

/**
 * Save image from data URL
 */
function saveImageFromDataUrl($dataUrl, $outputPath) {
    $extracted = extractBase64FromDataUrl($dataUrl);
    if (!$extracted) {
        throw new Exception('Invalid data URL format');
    }
    
    $imageData = base64_decode($extracted['data']);
    if ($imageData === false) {
        throw new Exception('Failed to decode base64 image data');
    }
    
    if (file_put_contents($outputPath, $imageData) === false) {
        throw new Exception("Failed to save image to: $outputPath");
    }
    
    echo "Image saved to: $outputPath\n";
    echo "Format: {$extracted['format']}, Size: " . strlen($imageData) . " bytes\n";
}

/**
 * Make API request
 */
function makeRequest($data) {
    global $API_KEY, $API_ENDPOINT;
    
    $url = "http://$API_ENDPOINT/api/v1/chat/completions";
    $postData = json_encode($data);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $API_KEY,
            'Content-Length: ' . strlen($postData)
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL error: $error");
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => $httpCode,
            'data' => $response
        ];
    }
    
    return [
        'status' => $httpCode,
        'data' => $decoded
    ];
}

/**
 * Extract image from message object
 */
function extractImageFromMessage($message) {
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
        // content can be a string or an array
        if (is_array($message['content'])) {
            // Find image content in the array
            foreach ($message['content'] as $item) {
                if (!is_array($item)) continue;
                
                // Check for image_url format
                if (isset($item['type']) && 
                    $item['type'] === 'image_url' && 
                    isset($item['image_url']['url'])) {
                    $url = $item['image_url']['url'];
                    if (is_string($url) && strpos($url, 'data:image/') === 0) {
                        return $url;
                    }
                }
                
                // Check for image format
                if (isset($item['type']) && 
                    $item['type'] === 'image' && 
                    isset($item['image']['data'])) {
                    $data = $item['image']['data'];
                    if (is_string($data) && strpos($data, 'data:image/') === 0) {
                        return $data;
                    }
                }
            }
        } elseif (is_string($message['content']) && strpos($message['content'], 'data:image/') === 0) {
            // Direct data URL in content string
            return $message['content'];
        }
    }
    
    return null;
}

/**
 * Main function
 */
function main() {
    global $API_KEY, $MODEL, $OUTPUT_DIR, $DEFAULT_PROMPT;
    
    try {
        // Parse command line arguments
        global $argc, $argv;
        if (!isset($argc) || $argc < 2) {
            echo "Usage: php image-editing.php <image_path> [modification_prompt]\n";
            echo "\n";
            echo "Examples:\n";
            echo "  php image-editing.php input.jpg \"make it look like a painting\"\n";
            echo "  php image-editing.php input.png \"add a sunset in the background\"\n";
            echo "  php image-editing.php input.jpg \"remove the background\"\n";
            echo "\n";
            echo "Environment Variables:\n";
            echo "  API_KEY_OVERRIDE - Your Rahyana API key\n";
            echo "  OUTPUT_DIR - Directory to save edited images (default: ./edited-images)\n";
            exit(1);
        }
        
        $imagePath = $argv[1];
        $modificationPrompt = $argv[2] ?? $DEFAULT_PROMPT;
        
        echo "Starting image editing...\n\n";
        
        // Check API key
        if ($API_KEY === 'YOUR_API_KEY_HERE') {
            echo "⚠️  Warning: Using default API key. Please set API_KEY_OVERRIDE environment variable or update the script.\n\n";
        }
        
        // Validate and read image
        echo "Reading image: $imagePath\n";
        $mimeType = getMimeType($imagePath);
        echo "Detected MIME type: $mimeType\n";
        
        // Resize if needed (API has size limits)
        $processedImagePath = resizeImageIfNeeded($imagePath);
        $isTempFile = ($processedImagePath !== $imagePath);
        
        $base64Image = encodeImageToBase64($processedImagePath);
        $imageSize = strlen(base64_decode($base64Image));
        echo "Image size: " . number_format($imageSize) . " bytes\n";
        echo "Base64 encoded size: " . number_format(strlen($base64Image)) . " bytes\n\n";
        
        // Ensure output directory exists
        ensureDirectoryExists($OUTPUT_DIR);
        
        // Prepare request payload
        $dataUrl = "data:$mimeType;base64,$base64Image";
        
        $requestData = [
            'model' => $MODEL,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $modificationPrompt
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl
                            ]
                        ]
                    ]
                ]
            ],
            'modalities' => ['image', 'text']
        ];
        
        echo "Modification prompt: $modificationPrompt\n";
        echo "Model: $MODEL\n";
        echo "\nSending request to API...\n\n";
        
        // Make API request
        $response = makeRequest($requestData);
        
        echo "Response status: {$response['status']}\n";
        
        if ($response['status'] !== 200) {
            echo "Error response: " . json_encode($response['data'], JSON_PRETTY_PRINT) . "\n";
            echo "\n⚠️  Got error status: {$response['status']}\n";
            echo "This could mean:\n";
            echo "  1. API key does not have access to this model\n";
            echo "  2. Model requires special permissions\n";
            echo "  3. Insufficient credits/balance\n";
            echo "  4. Model is not available or disabled\n";
            echo "  5. Image file is too large\n";
            exit(1);
        }
        
        // Extract image from response
        $responseData = $response['data'];
        
        $imageDataUrl = null;
        
        // First check providerResponse (provider-specific format)
        if (isset($responseData['providerResponse']['choices'][0]['message'])) {
            $message = $responseData['providerResponse']['choices'][0]['message'];
            $imageDataUrl = extractImageFromMessage($message);
        }
        
        // Fallback to top-level choices
        if (!$imageDataUrl && isset($responseData['choices'][0]['message'])) {
            $message = $responseData['choices'][0]['message'];
            $imageDataUrl = extractImageFromMessage($message);
        }
        
        if (!$imageDataUrl) {
            echo "No image found in response\n";
            echo "The API may have returned text instead of an image.\n";
            echo "Response content: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
            exit(1);
        }
        
        echo "Modified image data URL found (first 100 chars): " . substr($imageDataUrl, 0, 100) . "...\n";
        
        // Save edited image
        $inputFilename = pathinfo($imagePath, PATHINFO_FILENAME);
        $timestamp = time();
        $outputPath = $OUTPUT_DIR . '/' . $inputFilename . '-edited-' . $timestamp . '.png';
        
        saveImageFromDataUrl($imageDataUrl, $outputPath);
        
        // Clean up temporary resized file if created
        if ($isTempFile && file_exists($processedImagePath)) {
            @unlink($processedImagePath);
        }
        
        echo "\n✅ Image editing completed successfully!\n";
        echo "📁 Original image: $imagePath\n";
        echo "📁 Edited image saved to: $outputPath\n";
        
    } catch (Exception $e) {
        // Clean up temporary file on error
        if (isset($isTempFile) && $isTempFile && isset($processedImagePath) && file_exists($processedImagePath)) {
            @unlink($processedImagePath);
        }
        echo "Error: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}

// Run the script
if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}

