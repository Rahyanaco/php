<?php
/**
 * Rahyana API - Image Generation Example
 * 
 * This example demonstrates how to generate images using the Rahyana API
 * and save them to a local directory. Based on the Node.js implementation.
 * 
 * Usage:
 *   php image-generation.php
 * 
 * Environment Variables:
 *   API_KEY_OVERRIDE - Your Rahyana API key (optional, defaults to YOUR_API_KEY_HERE)
 *   OUTPUT_DIR - Directory to save images (optional, defaults to ./generated-images)
 */

// Configuration
$API_KEY = getenv('API_KEY_OVERRIDE') ?: 'YOUR_API_KEY_HERE';
$API_ENDPOINT = 'rahyana.ir';
$MODEL = 'google/gemini-3-pro-image-preview';
$PROMPT = 'a dog in a city';
$OUTPUT_DIR = getenv('OUTPUT_DIR') ?: __DIR__ . '/generated-images';

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
    
    $url = "https://$API_ENDPOINT/api/v1/chat/completions";
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
                if (!is_array($item)) {
                    continue;
                }
                
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
    global $API_KEY, $MODEL, $PROMPT, $OUTPUT_DIR;
    
    try {
        echo "Starting image generation...\n\n";
        
        // Check API key
        if ($API_KEY === 'YOUR_API_KEY_HERE') {
            echo "⚠️  Warning: Using default API key. Please set API_KEY_OVERRIDE environment variable or update the script.\n\n";
        }
        
        // Ensure output directory exists
        ensureDirectoryExists($OUTPUT_DIR);
        
        // Prepare request payload
        $requestData = [
            'model' => $MODEL,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $PROMPT
                ]
            ],
            'modalities' => ['image', 'text']
        ];
        
        echo "Request payload: " . json_encode($requestData, JSON_PRETTY_PRINT) . "\n";
        echo "\nSending request...\n\n";
        
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
            exit(1);
        }
        
        // Extract image from response
        $responseData = $response['data'];
        echo "\nResponse structure: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
        
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
            echo "Full response: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
            exit(1);
        }
        
        echo "\nImage data URL found (first 100 chars): " . substr($imageDataUrl, 0, 100) . "...\n";
        
        // Save image
        $timestamp = time();
        $outputPath = $OUTPUT_DIR . '/dog-in-city-' . $timestamp . '.png';
        
        saveImageFromDataUrl($imageDataUrl, $outputPath);
        
        echo "\n✅ Image generation completed successfully!\n";
        echo "📁 Image saved to: $outputPath\n";
        
    } catch (Exception $e) {
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

