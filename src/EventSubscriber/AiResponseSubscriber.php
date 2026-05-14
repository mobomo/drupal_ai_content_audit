<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\ProviderDisabledEvent;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to Drupal AI module events for the AI Content Audit module.
 *
 * Listens to the post-generation event to log assessment calls
 * and the provider-disabled event to handle graceful degradation.
 */
class AiResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => ['onPostGenerateResponse', 0],
      ProviderDisabledEvent::EVENT_NAME => ['onProviderDisabled', 0],
    ];
  }

  /**
   * Reacts to a completed AI generation response.
   *
   * Logs assessment calls tagged with 'ai_content_audit' for monitoring.
   */
  public function onPostGenerateResponse(PostGenerateResponseEvent $event): void {
    $tags = $event->getTags();
    // Only act on calls tagged by this module.
    if (!in_array('ai_content_audit', $tags, TRUE)) {
      return;
    }

    $logger = $this->loggerFactory->get('ai_content_audit');
    $logger->debug('AI assessment completed. Provider: @provider, Model: @model, Thread: @thread', [
      '@provider' => $event->getProviderId(),
      '@model' => $event->getModelId(),
      '@thread' => $event->getRequestThreadId(),
    ]);
  }

  /**
   * Reacts to an AI provider being disabled.
   *
   * Logs a warning so administrators know assessments may fail.
   */
  public function onProviderDisabled(ProviderDisabledEvent $event): void {
    $logger = $this->loggerFactory->get('ai_content_audit');
    $logger->warning('AI provider "@provider" was disabled. AI Content Audit assessments may fail until a new default provider is configured.', [
      '@provider' => $event->getProviderId(),
    ]);
  }

}
