<?php

declare(strict_types=1);

namespace Drupal\assetkiwi_connect\Utility;

/**
 * Resolves MIME types to icon classes and bundled buckets.
 *
 * No Drupal DI — keeps it testable with plain PHPUnit.
 */
final class MimeIconResolver {

  /**
   * Returns Drupal core's semantic icon class for a MIME type.
   *
   * Core 10.3+/11.x has IconMimeTypes::getIconClass(). Falls back to
   * file_icon_class() on older versions, or 'application-octet-stream'
   * outside a Drupal bootstrap.
   */
  public function getCoreIconClass(string $mimeType): string {
    if (class_exists('\Drupal\file\IconMimeTypes')) {
      return \Drupal\file\IconMimeTypes::getIconClass($mimeType);
    }
    if (function_exists('file_icon_class')) {
      return file_icon_class($mimeType);
    }
    return 'application-octet-stream';
  }

  /**
   * Maps MIME types to one of the bundled icon buckets.
   *
   * The module ships icons for: pdf, doc, spreadsheet, presentation, archive,
   * audio, video, image, text, generic. Independent of Drupal core's own
   * classification — ensures a visible icon regardless of active theme.
   */
  public function getIconBucket(string $mimeType): string {
    $mime = strtolower(trim($mimeType));
    if ($mime === 'application/pdf') {
      return 'pdf';
    }
    if (str_starts_with($mime, 'image/')) {
      return 'image';
    }
    if (str_starts_with($mime, 'video/')) {
      return 'video';
    }
    if (str_starts_with($mime, 'audio/')) {
      return 'audio';
    }
    if (in_array($mime, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text', 'application/rtf'], TRUE)) {
      return 'doc';
    }
    if (in_array($mime, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet', 'text/csv'], TRUE)) {
      return 'spreadsheet';
    }
    if (in_array($mime, ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.oasis.opendocument.presentation'], TRUE)) {
      return 'presentation';
    }
    if (in_array($mime, ['application/zip', 'application/x-tar', 'application/x-7z-compressed', 'application/x-rar-compressed', 'application/gzip', 'application/x-gzip'], TRUE)) {
      return 'archive';
    }
    if (str_starts_with($mime, 'text/')) {
      return 'text';
    }
    return 'generic';
  }

}
