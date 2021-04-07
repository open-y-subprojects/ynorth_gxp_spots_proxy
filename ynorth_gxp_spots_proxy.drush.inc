<?php

/**
 * @file
 * Drush integration.
 */

/**
 * Implements hook_drush_command().
 */
function ynorth_gxp_spots_proxy_drush_command() {
  $items['run_gxp_spots_syncer'] = [
    'description' => 'Run GXP spots syncer for new schedules.',
    'aliases' => ['sync-gxp-spots'],
    'callback' => 'ynorth_gxp_spots_proxy_callback',
    'examples' => [
      'drush sync-gxp-spots' => 'Start GXP spots syncer for new schedules.',
    ],
  ];
  return $items;
}

/**
 * Drush command callback.
 */
function ynorth_gxp_spots_proxy_callback() {
  $weeksLimit = 4;
  $date = new \DateTime('now');
  $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
  $service = \Drupal::service('ynorth_gxp_spots_proxy.week_proxy');
  for ($i = 0; $i < $weeksLimit; $i++) {
    $service->getWeekData($date->getTimestamp());
    $date->modify('+1 week');
  }

}
