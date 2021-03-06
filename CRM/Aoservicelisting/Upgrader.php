<?php
use CRM_Aoservicelisting_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Aoservicelisting_Upgrader extends CRM_Aoservicelisting_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
  public function install() {
    $this->executeSqlFile('sql/myinstall.sql');
  }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   *
  public function postInstall() {
    $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
      'return' => array("id"),
      'name' => "customFieldCreatedViaManagedHook",
    ));
    civicrm_api3('Setting', 'create', array(
      'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
    ));
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
  public function uninstall() {
   $this->executeSqlFile('sql/myuninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled.
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled.
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws Exception
   *
  public function upgrade_4200() {
    $this->ctx->log->info('Applying update 4200');
    CRM_Core_DAO::executeQuery('UPDATE foo SET bar = "whiz"');
    CRM_Core_DAO::executeQuery('DELETE FROM bang WHERE willy = wonka(2)');
    return TRUE;
  } // */

  public function upgrade_1200() {
    $this->ctx->log->info('Applying update 1200');

    $this->addTask(E::ts('Update Regulated Service Providers'), 'updateRegServiceProviders');
    $this->addTask(E::ts('Update Credentials Held'), 'updateCreds');
    return TRUE;
  }

  public function upgrade_1300() {
    $this->ctx->log->info('Applying update 1300');

    $updateContacts = CRM_Core_DAO::executeQuery("SELECT c.id, u.uf_id
      FROM civicrm_contact c
      INNER JOIN civicrm_relationship r ON r.contact_id_a = c.id
      LEFT JOIN civicrm_uf_match u ON u.contact_id = c.id
      WHERE (c.contact_sub_type = '' OR c.contact_sub_type IS NULL) AND r.relationship_type_id = 74")->fetchAll();
    if (!empty($updateContacts)) {
      foreach ($updateContacts as $contact) {
        civicrm_api3('Contact', 'create', [
          'contact_type' => 'Individual',
          'id' => $contact['id'],
          'contact_sub_type' => 'authorized_contact',
        ]);

        // Add user role too.
        if (!empty($contact['uf_id'])) {
          $user = \Drupal\user\Entity\User::load($contact['uf_id']);
          $roles = (array)$user->getRoles();
          if (!in_array('authorized_contact', $roles)) {
            $roles = array_merge($roles, ['authorized_contact']);
            $user->set('roles', array_unique($roles));
            $user->save();
          }
        }
      }
    }
    return TRUE;
  }

  public function updateRegServiceProviders() {
    $currentDetails = civicrm_api3('Contact', 'get', [
      'return' => [REGULATED_URL, REG_SER_IND],
      'contact_type' => 'Individual',
      'options' => ['limit' => 0],
    ]);
    if (!empty($currentDetails['values'])) {
      foreach ($currentDetails['values'] as $detail) {
        if (empty($detail[REG_SER_IND]) && !empty($detail[REGULATED_URL])) {
          // This is a regulated service provider, update the profession if not found.
          $regulatorUrlMapping = CRM_Core_OptionGroup::values('regulator_url_mapping');
          $regulatedServicesProvided = CRM_Core_OptionGroup::values('regulated_services_provided_20200226231106');
          $serviceProvided = NULL;
          foreach ($regulatorUrlMapping as $value => $domains) {
            $parts = (array) explode(',', $domains);
            foreach ($parts as $domain) {
              if (stristr($detail[REGULATED_URL], $domain) !== FALSE) {
                $serviceProvided = $regulatedServicesProvided[$value];
                break;
              }
            }
          }
          civicrm_api3('Contact', 'create', [
            'contact_id' => $detail['id'],
            REG_SER_IND => [$serviceProvided],
          ]);
        }
      }
    }
    return TRUE;
  }

  public function updateCreds() {
    $currentDetails = civicrm_api3('Contact', 'get', [
      'return' => [CERTIFICATE_NUMBER, CRED_HELD_IND],
      'contact_type' => 'Individual',
      'options' => ['limit' => 0],
    ]);
    if (!empty($currentDetails['values'])) {
      foreach ($currentDetails['values'] as $detail) {
        if (empty($detail[CRED_HELD_IND]) && !empty($detail[CERTIFICATE_NUMBER])) {
          // This is a certified staff member, update the certificate type if not found.
          $firstChar = (string) strtoupper(substr($detail[CERTIFICATE_NUMBER], 0, 1));
          $certType = NULL;
          switch($firstChar) {
            case '0':
              $certType = "BCaBA";
              break;
            case '1':
              $certType = "BCBA";
              break;
            case 'R':
              $certType = "RBT";
              break;
            default:
              break;
          }
          if (!empty($certType)) {
            civicrm_api3('Contact', 'create', [
              'contact_id' => $detail['id'],
              CRED_HELD_IND => [$certType],
            ]);
          }
        }
      }
    }
    return TRUE;
  }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = E::ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
