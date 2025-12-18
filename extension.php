<?php

declare(strict_types=1);

require __DIR__ . "/autoloader.php";

use League\HTMLToMarkdown\HtmlConverter;

class DiscordExtension extends Minz_Extension
{
  #[\Override]
  public function init(): void
  {
    $this->registerTranslates();
    $this->registerHook("entry_before_add", [$this, "handleEntryBeforeAdd"]);
  }

  public function handleConfigureAction(): void
  {
    $this->registerTranslates();

    if (Minz_Request::isPost()) {
      $now = new DateTime();
      $test = Minz_Request::hasParam("test");
      $config = [
        "url" => Minz_Request::paramString("url"),
        "username" => Minz_Request::paramString("username"),
        "avatar_url" => Minz_Request::paramString("avatar_url"),
        "ignore_autoread" => Minz_Request::paramBoolean("ignore_autoread"),
        "embed_as_link_patterns" => Minz_Request::paramString("embed_as_link_patterns"),
		"embed_as_image_patterns" => Minz_Request::paramString("embed_as_image_patterns"),
      ];

      $this->setSystemConfiguration($config);

      if ($test) {
        $this->sendMessage($config["url"], $config["username"], $config["avatar_url"], [
          "content" => "Test message from FreshRSS posted at " . $now->format("m/d/Y H:i:s"),
        ]);
      }
    }
  }

  public function handleEntryBeforeAdd($entry)
  {
    $shouldIgnoreAutoread = $this->getSystemConfigurationValue("ignore_autoread", false);

    if ($shouldIgnoreAutoread && $entry->isRead()) {
      return $entry;
    }

    $url = $entry->link();

    $embedAsLinkPatterns = $this->getSystemConfigurationValue("embed_as_link_patterns", "");
    $embedAsLinkPatterns = array_filter(array_map('trim', explode("\n", $embedAsLinkPatterns)));
    $embedAsLink = false;

    foreach ($embedAsLinkPatterns as $pattern) {
      if (!empty($pattern) && @preg_match($pattern, $url)) {
        $embedAsLink = true;
        break;
      }
    }

    $embedAsImagePatterns = $this->getSystemConfigurationValue("embed_as_image_patterns", "");
    $embedAsImagePatterns = array_filter(array_map('trim', explode("\n", $embedAsImagePatterns)));
    $embedAsImage = false;

    foreach ($embedAsImagePatterns as $pattern) {
      if (!empty($pattern) && @preg_match($pattern, $url)) {
        $embedAsImage = true;
        break;
      }
    }

    if ($embedAsLink) {
      $this->sendMessage(
        $this->getSystemConfigurationValue("url"),
        $this->getSystemConfigurationValue("username"),
        $this->getSystemConfigurationValue("avatar_url"),
        ["content" => $url]
      );
    } elseif ($embedAsImage) {
      $this->sendImageMessage(
        $this->getSystemConfigurationValue("url"),
        $this->getSystemConfigurationValue("username"),
        $this->getSystemConfigurationValue("avatar_url"),
        $url
      );
    } else {
      $converter = new HtmlConverter(["strip_tags" => true]);
      $thumb = $entry->thumbnail();
      $descr = $entry->originalContent();
      $embed = [
        "url" => $url,
        "title" => $entry->title(),
        "color" => 2605643,
        "description" => $this->truncate($converter->convert($descr), 4000),
        "timestamp" => (new DateTime("@" . $entry->date(true) / 1000))->format(DateTime::ATOM),
        "author" => [
          "name" => $entry->feed()->name(),
          "icon_url" => $this->favicon($entry->feed()->website()),
        ],
        "footer" => [
          "text" => $this->getSystemConfigurationValue("username"),
          "icon_url" => $this->getSystemConfigurationValue("avatar_url"),
        ],
      ];

      if ($thumb !== null) {
        $embed["thumbnail"] = array_filter([
          "url" => $thumb["url"],
          "width" => $thumb["width"] ?? null,
          "height" => $thumb["height"] ?? null,
        ]);
      }

      $this->sendMessage(
        $this->getSystemConfigurationValue("url"),
        $this->getSystemConfigurationValue("username"),
        $this->getSystemConfigurationValue("avatar_url"),
        ["embeds" => [$embed]]
      );
    }

    return $entry;
  }

  public function sendImageMessage($url, $username, $avatar_url, $image_url)
  {
    try {
      // Download the image content
      $image_content = $this->downloadImage($image_url);

      if ($image_content === false) {
        Minz_Log::error("[Discord] ❌ Failed to download image: " . $image_url);
        return;
      }

      // Get image info
      $image_info = getimagesizefromstring($image_content);

      if ($image_info === false) {
        Minz_Log::error("[Discord] ❌ Invalid image format: " . $image_url);
        return;
      }

      // Prepare form data for file upload
      $boundary = '----FreshRSS-Discord-' . uniqid();
      $data = [
        "username" => $username,
        "avatar_url" => $avatar_url,
        "file" => [
          "name" => basename($image_url),
          "type" => $image_info['mime'],
          "contents" => $image_content,
        ],
      ];

      // Build multipart form data
      $post_fields = [];
      foreach ($data as $name => $value) {
        if (is_array($value)) {
          $post_fields[] = "--" . $boundary;
          $post_fields[] = "Content-Disposition: form-data; name=\"file\"; filename=\"" . $value['name'] . "\"";
          $post_fields[] = "Content-Type: " . $value['type'];
          $post_fields[] = "";
          $post_fields[] = $value['contents'];
        } else {
          $post_fields[] = "--" . $boundary;
          $post_fields[] = "Content-Disposition: form-data; name=\"" . $name . "\"";
          $post_fields[] = "";
          $post_fields[] = $value;
        }
      }
      $post_fields[] = "--" . $boundary . "--";
      $post_fields[] = "";
      $payload = implode("\r\n", $post_fields);

      // Send request
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: multipart/form-data; boundary=" . $boundary,
      ]);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Get response
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

      curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      // Check for 413 Payload Too Large error
      if ($http_code === 413) {
        Minz_Log::error("[Discord] ⚠️ Image too large (413), falling back to embedded link");
        Minz_Log::error("[Discord] ⚠️ Image URL: " . $image_url);
        Minz_Log::error("[Discord] ⚠️ Size: " . strlen($image_content) . " bytes");

        // Fallback to embedded image link
        $this->sendMessage($url, $username, $avatar_url, ["content" => $image_url]);
      } elseif ($http_code >= 200 && $http_code < 300) {
        Minz_Log::info("[Discord] ✅ Image uploaded successfully");
      } else {
        Minz_Log::error("[Discord] ❌ Image upload failed (HTTP " . $http_code . ")");
      }
    } catch (Throwable $err) {
      Minz_Log::error("[Discord] ❌ " . $err);
    } finally {
      curl_close($ch);
    }
  }

  public function downloadImage(string $url): string|false
  {
    try {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      $content = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      curl_close($ch);

      if ($http_code >= 200 && $http_code < 300) {
        return $content;
      }
    } catch (Throwable $err) {
      Minz_Log::error("[Discord] ❌ Image download error: " . $err);
    }

    return false;
  }

  public function sendMessage($url, $username, $avatar_url, $body)
  {
    try {
      $ch = curl_init($url);
      $data = [
        "username" => $username,
        "avatar_url" => $avatar_url,
      ];

      if (isset($body["content"])) {
        $data["content"] = $body["content"];
      }

      if (isset($body["embeds"])) {
        $data["embeds"] = $body["embeds"];
      }

      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
      curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
      curl_exec($ch);
    } catch (Throwable $err) {
      Minz_Log::error("[Discord] ❌ " . $err);
    } finally {
      curl_close($ch);
    }
  }

  public function debug(mixed $any): void
  {
    $file = __DIR__ . "/debug.txt";

    file_put_contents($file, print_r($any, true), FILE_APPEND);
    file_put_contents($file, "\n----------------------\n\n", FILE_APPEND);
  }

  public function favicon(string $url): string
  {
    return "https://favicon.im/" . parse_url($url, PHP_URL_HOST);
  }

  public function truncate(string $text, int $length = 20): string
  {
    if (strlen($text) <= $length) {
      return $text;
    }

    $text = substr($text, 0, $length);
    $text = substr($text, 0, strrpos($text, " "));
    $text .= "...";

    return $text;
  }
}
