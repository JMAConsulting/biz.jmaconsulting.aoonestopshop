<?php
require_once 'aoservicelisting.constants.inc';

// AUTO-GENERATED FILE -- Civix may overwrite any changes made to this file

/**
 * The ExtensionUtil class provides small stubs for accessing resources of this
 * extension.
 */
class CRM_Aoservicelisting_ExtensionUtil {
  const SHORT_NAME = "aoservicelisting";
  const LONG_NAME = "biz.jmaconsulting.aoservicelisting";
  const CLASS_PREFIX = "CRM_Aoservicelisting";

  /**
   * Translate a string using the extension's domain.
   *
   * If the extension doesn't have a specific translation
   * for the string, fallback to the default translations.
   *
   * @param string $text
   *   Canonical message text (generally en_US).
   * @param array $params
   * @return string
   *   Translated text.
   * @see ts
   */
  public static function ts($text, $params = []) {
    if (!array_key_exists('domain', $params)) {
      $params['domain'] = [self::LONG_NAME, NULL];
    }
    return ts($text, $params);
  }

  /**
   * Get the URL of a resource file (in this extension).
   *
   * @param string|NULL $file
   *   Ex: NULL.
   *   Ex: 'css/foo.css'.
   * @return string
   *   Ex: 'http://example.org/sites/default/ext/org.example.foo'.
   *   Ex: 'http://example.org/sites/default/ext/org.example.foo/css/foo.css'.
   */
  public static function url($file = NULL) {
    if ($file === NULL) {
      return rtrim(CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME), '/');
    }
    return CRM_Core_Resources::singleton()->getUrl(self::LONG_NAME, $file);
  }

  /**
   * Get the path of a resource file (in this extension).
   *
   * @param string|NULL $file
   *   Ex: NULL.
   *   Ex: 'css/foo.css'.
   * @return string
   *   Ex: '/var/www/example.org/sites/default/ext/org.example.foo'.
   *   Ex: '/var/www/example.org/sites/default/ext/org.example.foo/css/foo.css'.
   */
  public static function path($file = NULL) {
    // return CRM_Core_Resources::singleton()->getPath(self::LONG_NAME, $file);
    return __DIR__ . ($file === NULL ? '' : (DIRECTORY_SEPARATOR . $file));
  }

  /**
   * Get the name of a class within this extension.
   *
   * @param string $suffix
   *   Ex: 'Page_HelloWorld' or 'Page\\HelloWorld'.
   * @return string
   *   Ex: 'CRM_Foo_Page_HelloWorld'.
   */
  public static function findClass($suffix) {
    return self::CLASS_PREFIX . '_' . str_replace('\\', '_', $suffix);
  }

  public static function verifyListing($duration) {
    // Get a list of service listings who have been approved with the latest approval activity.
    // Duration is specified in months, so multiply by 4.345
    $weeks = (integer) ($duration * 4.345) - 1;
    if (empty($weeks)) {
      $weeks = 25;
    }
    // Create temporary table holding all activities ordered by
    $result = [];
    $sql = "SELECT cac.contact_id, DATE(act.activity_date_time) AS activity_date
      FROM civicrm_activity act 
      INNER JOIN civicrm_activity_contact  cac 
      ON cac.activity_id = act.id AND cac.record_type_id = 3
      INNER JOIN (
        SELECT cac.contact_id contact_id, MAX(activity_date_time) activity_date_time
        FROM civicrm_activity ca
        INNER JOIN civicrm_activity_contact cac 
        ON cac.activity_id = ca.id AND cac.record_type_id = 3
        WHERE activity_type_id = 142
        AND ca.subject = \"Application status changed to Approved\"
        AND ca.is_current_revision = 1
        GROUP BY cac.contact_id
      ) AS temp 
      ON temp.contact_id = cac.contact_id
      AND temp.activity_date_time = act.activity_date_time
      INNER JOIN civicrm_value_service_listi_71 s ON s.entity_id = cac.contact_id
      INNER JOIN civicrm_contact cc ON cc.id = cac.contact_id
      WHERE DATE_ADD(DATE(act.activity_date_time), INTERVAL $weeks WEEK) = DATE(NOW())
      AND s.service_provider_status_872 = 'Approved'
      AND act.subject = \"Application status changed to Approved\"
      AND act.is_current_revision = 1
      AND cc.is_deleted <> 1
      GROUP BY cac.contact_id";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      // Send an email to the authorized personnel for these contacts indicating they need to submit listing for re-verification.
      $relationship = civicrm_api3('Relationship', 'get', [
        'contact_id_b' => $dao->contact_id,
        'relationship_type_id' => PRIMARY_CONTACT_REL,
        'return' => 'contact_id_a',
        'is_active' => 1,
      ]);
      if ($relationship['count'] > 0 && !empty($relationship['values'][$relationship['id']]['contact_id_a'])) {
        $primaryCid = $relationship['values'][$relationship['id']]['contact_id_a'];
      }
      if (empty($primaryCid)) {
        continue;
      }
      self::sendMessage($dao->contact_id, VERIFICATION_MSG, $primaryCid);

      // Create activity indicating verification was sent.
      civicrm_api3('Activity', 'create', [
        'source_contact_id' => $dao->contact_id,
        'assignee_id' => SPECIALIST_ID,
        'status_id' => 'Completed',
        'target_id' => $dao->contact_id,
        'activity_type_id' => "service_listing_verification",
        'sequential' => 0,
      ]);

      $result['contacts_emailed'][] = $primaryCid;
    }

    // Check also if we have listings 6 months since date of approval.
    // This means they have not re-verified their listing.
    $weeks = $weeks + 1;
    $sql = "SELECT cac.contact_id, DATE(act.activity_date_time) AS activity_date
      FROM civicrm_activity act 
      INNER JOIN civicrm_activity_contact  cac 
      ON cac.activity_id = act.id AND cac.record_type_id = 3
      INNER JOIN (
        SELECT cac.contact_id contact_id, MAX(activity_date_time) activity_date_time
        FROM civicrm_activity ca
        INNER JOIN civicrm_activity_contact cac 
        ON cac.activity_id = ca.id AND cac.record_type_id = 3
        WHERE activity_type_id = 142
        AND ca.subject = \"Application status changed to Approved\"
        AND ca.is_current_revision = 1
        GROUP BY cac.contact_id
      ) AS temp 
      ON temp.contact_id = cac.contact_id
      AND temp.activity_date_time = act.activity_date_time
      INNER JOIN civicrm_value_service_listi_71 s ON s.entity_id = cac.contact_id
      WHERE DATE_ADD(DATE(act.activity_date_time), INTERVAL $weeks WEEK) = DATE(NOW())
      AND s.service_provider_status_872 = 'Approved'
      AND act.subject = \"Application status changed to Approved\"
      AND act.is_current_revision = 1
      GROUP BY cac.contact_id";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      // We change the service listing status to indicate that the listing has expired.
      civicrm_api3('Contact', 'create', [
        'id' => $dao->contact_id,
        STATUS => 'Expired',
        'contact_type' => 'Organization',
        'contact_sub_type' => 'service_provider',
      ]);

      // Create an activity too, for the status change.
      civicrm_api3('Activity', 'create', [
        'source_contact_id' => $dao->contact_id,
        'activity_type_id' => "provider_status_changed",
        'subject' => "Application status changed to Expired",
        'activity_status_id' => 'Completed',
        'target_id' => $dao->contact_id,
        'assignee_id' => SPECIALIST_ID,
      ]);

      // Send an email to these contacts indicating their listing has now expired.
      self::sendMessage($dao->contact_id, EXPIRED_MSG);

      $result['contacts_expired'][] = $dao->contact_id;
    }

    return $result;
  }

  public static function sendMessage($contactID, $msgId, $applicantID = NULL) {
    if (empty($contactID)) {
      return;
    }
    $messageTemplates = new CRM_Core_DAO_MessageTemplate();
    $messageTemplates->id = $msgId;
    $messageTemplates->find(TRUE);

    $body_subject = CRM_Core_Smarty::singleton()->fetch("string:$messageTemplates->msg_subject");
    $body_text    = $messageTemplates->msg_text;
    $body_html    = "{crmScope extensionKey='biz.jmaconsulting.aoservicelisting'}" . $messageTemplates->msg_html . "{/crmScope}";
    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contactID]);

    if ($msgId == ACKNOWLEDGE_MESSAGE && !empty($applicantID)) {
      $contact['email'] = ACKNOWLEDGE_SENDER;
      $contact['display_name'] = CRM_Contact_BAO_Contact::displayName(SPECIALIST_ID);
      $url = CRM_Utils_System::url("civicrm/contact/view", "reset=1&cid=" . $applicantID, TRUE);
      $body_text  = str_replace('{url}', $url, $messageTemplates->msg_text);
      $body_html  = str_replace('{url}', $url, $messageTemplates->msg_html);
    }
    if ($msgId == VERIFICATION_MSG && !empty($applicantID)) {
      $contact = civicrm_api3('Contact', 'getsingle', ['id' => $applicantID]);
      $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
      $url = CRM_Utils_System::url("civicrm/service-listing-application", "reset=1&cid=" . $contactID . '&cs=' . $cs, TRUE);
      $body_text  = str_replace('{verificationurl}', $url, $messageTemplates->msg_text);
      $body_html  = str_replace('{verificationurl}', $url, $messageTemplates->msg_html);
    }
    $body_html = CRM_Core_Smarty::singleton()->fetch("string:{$body_html}");
    $body_text = CRM_Core_Smarty::singleton()->fetch("string:{$body_text}");
    $mailParams = array(
      'groupName' => 'Service Application Listing Confirmation',
      'from' => FROM_EMAIL,
      'toName' =>  $contact['display_name'],
      'toEmail' => $contact['email'],
      'subject' => $body_subject,
      'messageTemplateID' => $messageTemplates->id,
      'html' => $body_html,
      'text' => $body_text,
    );
    CRM_Utils_Mail::send($mailParams);
  }

  public static function createActivity($cid) {
    civicrm_api3('Activity', 'create', [
      'source_contact_id' => $cid,
      'assignee_id' => SPECIALIST_ID,
      'status_id' => 'Scheduled',
      'target_id' => $cid,
      'activity_type_id' => "service_listing_created",
      'sequential' => 0,
    ]);
    //self::sendMessage(SPECIALIST_ID, ACKNOWLEDGE_MESSAGE, $cid);
  }

  public static function editActivity($cid) {
    civicrm_api3('Activity', 'create', [
      'source_contact_id' => $cid,
      'assignee_id' => SPECIALIST_ID,
      'status_id' => 'Completed',
      'target_id' => $cid,
      'activity_type_id' => "service_listing_edited",
      'sequential' => 0,
    ]);
  }

  public static function submissionActivity($logger, $cid) {
    $description = implode("<br/>", $logger);
    civicrm_api3('Activity', 'create', [
      'source_contact_id' => $cid,
      'assignee_id' => SPECIALIST_ID,
      'status_id' => 'Completed',
      'target_id' => $cid,
      'activity_type_id' => "service_listing_submission",
      'sequential' => 0,
      'details' => $description,
    ]);
  }

  public static function checkPrimaryContact($cid) {
    $authorizedContact = civicrm_api3('Contact', 'get', [
      'id' => $cid,
      'contact_sub_type' => "authorized_contact",
    ]);
    if (!empty($authorizedContact['count']) && $authorizedContact['count'] == 1) {
      // The current contact is a primary contact.
      return TRUE;
    }
    return FALSE;
  }

  public static function endRelationship($values, $rowNumber, $orgId) {
    if (empty($values['staff_first_name'][$rowNumber]) && empty($values['staff_last_name'][$rowNumber])
      && empty($values['staff_record_regulator'][$rowNumber]) && !empty($values['staff_contact_id'][$rowNumber])) {
      $currentDetails = civicrm_api3('Contact', 'getsingle', [
        'id' => $values['staff_contact_id'][$rowNumber],
        'return' => ['first_name', 'last_name', REGULATED_URL, CERTIFICATE_NUMBER, REG_SER_IND],
      ]);
      if (!empty($currentDetails[CERTIFICATE_NUMBER])) {
        // Archive the regulated URL
        self::archiveField(REGULATED_URL, $currentDetails[REGULATED_URL], $values['staff_contact_id'][$rowNumber]);
        self::archiveField(REG_SER_IND, $currentDetails[REG_SER_IND], $values['staff_contact_id'][$rowNumber]);
      }
      else {
        if (self::checkPrimaryContact($values['staff_contact_id'][$rowNumber])) {
          // Only archive field, don't terminate relationship
          self::archiveField(REGULATED_URL, $currentDetails[REGULATED_URL], $values['staff_contact_id'][$rowNumber]);
          self::archiveField(REG_SER_IND, $currentDetails[REG_SER_IND], $values['staff_contact_id'][$rowNumber]);
        }
        else {
          // We had a staff record but it is gone now
          self::terminateRelationship($values['staff_contact_id'][$rowNumber], $orgId);
        }
      }
    }
  }

  public static function endABARelationship($values, $rowNumber, $orgId) {
    if (empty($values['aba_first_name'][$rowNumber]) && empty($values['aba_last_name'][$rowNumber])
      && empty($values[CERTIFICATE_NUMBER][$rowNumber]) && !empty($values['aba_contact_id'][$rowNumber])) {
      $currentDetails = civicrm_api3('Contact', 'getsingle', [
        'id' => $values['aba_contact_id'][$rowNumber],
        'return' => ['first_name', 'last_name', REGULATED_URL, CERTIFICATE_NUMBER, CRED_HELD_IND],
      ]);
      if (!empty($currentDetails[REGULATED_URL])) {
        // Archive the regulated URL
        self::archiveField(CERTIFICATE_NUMBER, $currentDetails[CERTIFICATE_NUMBER], $values['aba_contact_id'][$rowNumber]);
        self::archiveField(CRED_HELD_IND, $currentDetails[CRED_HELD_IND], $values['aba_contact_id'][$rowNumber]);
      }
      else {
        if (self::checkPrimaryContact($values['aba_contact_id'][$rowNumber])) {
          // Only archive field, don't terminate relationship
          self::archiveField(CERTIFICATE_NUMBER, $currentDetails[CERTIFICATE_NUMBER], $values['aba_contact_id'][$rowNumber]);
          self::archiveField(CRED_HELD_IND, $currentDetails[CRED_HELD_IND], $values['aba_contact_id'][$rowNumber]);
        }
        else {
          // We had a staff record but it is gone now
          self::terminateRelationship($values['aba_contact_id'][$rowNumber], $orgId);
        }
      }
    }
  }

  public static function terminateRelationship($cid, $orgId) {
    $params = ['contact_id_a' => $cid, 'contact_id_b' => $orgId, 'is_active' => 1];
    $relationships = civicrm_api3('Relationship', 'get', $params);
    if (!empty($relationships['values'])) {
      // End Date all relationships as they have either overwritten the data or not.
      foreach ($relationships['values'] as $relationship) {
        civicrm_api3('Relationship', 'create', ['id' => $relationship['id'], 'is_active' => 0, 'relationship_type_id' => $relationship['relationship_type_id'], 'end_date' => date('Y-m-d')]);
      }
    }
  }

  public static function archiveField($field, $value, $cid) {
    civicrm_api3('Note', 'create', [
      'entity_id' => $cid,
      'note' => $value,
      'entity_table' => "civicrm_contact",
    ]);
    civicrm_api3('Contact', 'create', [
      'contact_type' => "Individual",
      'id' => $cid,
      $field => 'null',
    ]);
  }

  public static function findDupes($cid, $orgId, &$individualParams, $rel = EMPLOYER_CONTACT_REL, $isOrgOptional = FALSE, $staffType) {
    if (!empty($cid)) {
      $currentDetails = civicrm_api3('Contact', 'getsingle', [
        'id' => $cid,
        'return' => ['first_name', 'last_name', REGULATED_URL, CERTIFICATE_NUMBER, REG_SER_IND, CRED_HELD_IND],
      ]);
      // Check, based on staff type
      if ($staffType == 'regstaff') {
        // Get saved regulator URL
        if ($currentDetails['first_name'] != $individualParams['first_name'] &&
          $currentDetails['last_name'] != $individualParams['last_name'] &&
          $currentDetails[REGULATED_URL] != $individualParams['regulated_url']) {
          // This is an overwrite on the record, check to see if this contact has an ABA certificate before ending the relationship.
          if (!empty($currentDetails[CERTIFICATE_NUMBER])) {
            // Move the certificate number elsewhere and delete the certificate number, don't end the relationship.
            self::archiveField(REGULATED_URL, $currentDetails[REGULATED_URL], $cid);
            // Also move the certificate type elsewhere and delete it.
            self::archiveField(REG_SER_IND, $currentDetails[REG_SER_IND], $cid);
          }
          else {
            // There is no certificate number, we can now end the relationship.
            if (self::checkPrimaryContact($cid)) {
              // Only archive field, don't terminate relationship
              self::archiveField(REGULATED_URL, $currentDetails[REGULATED_URL], $cid);
              self::archiveField(REG_SER_IND, $currentDetails[REG_SER_IND], $cid);
            }
            else {
              self::terminateRelationship($cid, $orgId);
            }
          }
        }
        else {
          // This is an edit, return the contact ID.
          if ($currentDetails['first_name'] == $individualParams['first_name'] &&
            $currentDetails['last_name'] == $individualParams['last_name'] &&
            $currentDetails[REGULATED_URL] != $individualParams['regulated_url']) {
            // This is an edit to only the regulated URL field, primary contacts can be edited.
            $individualParams['contact_id'] = $cid;
            return;
          }
          if (($currentDetails['first_name'] != $individualParams['first_name'] ||
            $currentDetails['last_name'] != $individualParams['last_name']) &&
            $currentDetails[REGULATED_URL] == $individualParams['regulated_url']) {
            // This is an edit to the name, ensure that it is not a primary contact.
            if (self::checkPrimaryContact($cid)) {
              self::archiveField(REGULATED_URL, $currentDetails[REGULATED_URL], $cid);
              self::archiveField(REG_SER_IND, $currentDetails[REG_SER_IND], $cid);
            }
            else {
              $individualParams['contact_id'] = $cid;
            }
          }
          if ($currentDetails['first_name'] == $individualParams['first_name'] &&
            $currentDetails['last_name'] == $individualParams['last_name'] &&
            $currentDetails[REGULATED_URL] == $individualParams['regulated_url']) {
            // No edits, return the contact id.
            $individualParams['contact_id'] = $cid;
          }
        }
      }
      elseif ($staffType == 'abastaff') {
        // Get saved certificate number
        if ($currentDetails['first_name'] != $individualParams['first_name'] &&
          $currentDetails['last_name'] != $individualParams['last_name'] &&
          $individualParams[CERTIFICATE_NUMBER] != $currentDetails[CERTIFICATE_NUMBER]) {
          // This is an overwrite on the record, check to see if this contact has a regulated URL before ending the relationship.
          if (!empty($currentDetails[REGULATED_URL])) {
            // Move the certificate number elsewhere and delete the certificate number, don't end the relationship.
            self::archiveField(CERTIFICATE_NUMBER, $currentDetails[CERTIFICATE_NUMBER], $cid);
            self::archiveField(CRED_HELD_IND, $currentDetails[CRED_HELD_IND], $cid);
          }
          else {
            // There is no certificate number, we can now end the relationship.
            if (self::checkPrimaryContact($cid)) {
              // Only archive field, don't terminate relationship
              self::archiveField(CERTIFICATE_NUMBER, $currentDetails[CERTIFICATE_NUMBER], $cid);
              self::archiveField(CRED_HELD_IND, $currentDetails[CRED_HELD_IND], $cid);
            }
            else {
              self::terminateRelationship($cid, $orgId);
            }
          }
        }
        else {
          // This is an edit, return the contact ID.
          if ($currentDetails['first_name'] == $individualParams['first_name'] &&
            $currentDetails['last_name'] == $individualParams['last_name'] &&
            $currentDetails[CERTIFICATE_NUMBER] != $individualParams[CERTIFICATE_NUMBER]) {
            // This is an edit to only the certificate field, primary contacts can be edited.
            $individualParams['contact_id'] = $cid;
            return;
          }
          if (($currentDetails['first_name'] != $individualParams['first_name'] ||
              $currentDetails['last_name'] != $individualParams['last_name']) &&
            $currentDetails[CERTIFICATE_NUMBER] == $individualParams[CERTIFICATE_NUMBER]) {
            // This is an edit to the name, ensure that it is not a primary contact.
            if (self::checkPrimaryContact($cid)) {
              self::archiveField(CERTIFICATE_NUMBER, $currentDetails[CERTIFICATE_NUMBER], $cid);
              self::archiveField(CRED_HELD_IND, $currentDetails[CRED_HELD_IND], $cid);
            }
            else {
              $individualParams['contact_id'] = $cid;
            }
          }
          if ($currentDetails['first_name'] == $individualParams['first_name'] &&
            $currentDetails['last_name'] == $individualParams['last_name'] &&
            $currentDetails[CERTIFICATE_NUMBER] == $individualParams[CERTIFICATE_NUMBER]) {
            // No edits, return the contact id.
            $individualParams['contact_id'] = $cid;
          }
        }
      }
    }
    else {
      $orgClause = ' r.contact_id_b = ' . $orgId;
      $orgClause = $isOrgOptional ? " ($orgClause OR r.contact_id_b IS NOT NULL) " : $orgClause;
      // Check for dupes.
      $existingStaff = CRM_Core_DAO::singleValueQuery("SELECT r.contact_id_a
        FROM civicrm_relationship r
        INNER JOIN civicrm_contact cb ON cb.id = r.contact_id_b
        LEFT JOIN civicrm_contact ca ON ca.id = r.contact_id_a
        WHERE $orgClause AND r.relationship_type_id = %2 AND r.is_active = 1
        AND ca.first_name = %3 AND ca.last_name = %4",
        [2 => [$rel, "Integer"], 3 => [$individualParams['first_name'], 'String'], 4 => [$individualParams['last_name'], 'String']]); // We expect only a single contact
      if (!empty($existingStaff)) {
        // Dupe found
        $individualParams['contact_id'] = $existingStaff;
      }
    }
  }

  public static function createWebsite($cid, $url) {
    $result = civicrm_api3('Website', 'get', [
      'contact_id' => $cid,
      'url' => $url,
      'return' => 'id',
      'options' => ['limit' => 1],
    ]);
    if (!empty($result['id'])) {
      return;
    }
    civicrm_api3('Website', 'create', [
      'website_type_id' => 'Work',
      'url' => $url,
      'contact_id' => $cid,
    ]);
  }

  public static function createRegulatedURL($cid, $url) {
    if (empty($url)) {
      return;
    }
    // Determine the profession from the URL, and add to the contact record.
    $regulatorUrlMapping = CRM_Core_OptionGroup::values('regulator_url_mapping');
    $regulatedServicesProvided = CRM_Core_OptionGroup::values('regulated_services_provided_20200226231106');
    $serviceProvided = [];
    foreach ($regulatorUrlMapping as $value => $domains) {
      $parts = (array) explode(',', $domains);
      foreach ($parts as $domain) {
        if (stristr($url, $domain) !== FALSE) {
          $serviceProvided = [$regulatedServicesProvided[$value]];
          break;
        }
      }
    }
    civicrm_api3('Contact', 'create', [
      REGULATED_URL => $url,
      'contact_id' => $cid,
      REG_SER_IND => $serviceProvided,
    ]);
  }

  public static function getCredential($cert) {
    if (empty($cert)) {
      return NULL;
    }
    // Get first character of the certificate number.
    $firstChar = (string) strtoupper(substr($cert, 0, 1));
    $certType = [];
    switch($firstChar) {
      case '0':
        $certType = ["BCaBA"];
        break;
      case '1':
        $certType = ["BCBA"];
        break;
      case 'R':
        $certType = ["RBT"];
        break;
      default:
        break;
    }
    return $certType;
  }

  public static function createPhone($cid, $phone) {
    if (empty($phone)) {
      return;
    }
    $params = [
      'phone' => $phone,
      'location_type_id' => 'Work',
      'contact_id' => $cid,
      'phone_type_id' => 'Phone',
      'is_primary' => 1,
    ];
    $result = civicrm_api3('Phone', 'get', $params);
    if (!empty($result['values'])) {
      return;
    }
    $phone = civicrm_api3('Phone', 'create', $params);
    return $phone['id'];
  }

  public static function createRelationship($cid, $orgId, $relType) {
    $relationshipParams = [
      'contact_id_a' => $cid,
      'contact_id_b' => $orgId,
      'relationship_type_id' => $relType,
    ];
    $relationshipCheck = civicrm_api3('Relationship', 'get', $relationshipParams);
    if ($relationshipCheck['count'] < 1) {
      try {
        civicrm_api3('Relationship', 'create', $relationshipParams);
      } catch (Exception $e) {}
    }
  }

  public static function createAddress($values, $rowNumber, $cid, $addId = NULL) {
    $addressParams = [
      'street_address' => $values['work_address'][$rowNumber],
      'city' => $values['city'][$rowNumber],
      'postal_code' => $values['postal_code'][$rowNumber],
      'contact_id' => $cid,
      'country_id' => 'CA',
      'state_province_id' => 'Ontario',
      'location_type_id' => 'Work',
    ];
    if ($rowNumber == 1) {
      $addressParams['is_primary'] = 1;
    }
    if (!empty($addId)) {
      $addressParams['id'] = $addId;
    }
    else {
      $result = civicrm_api3('Address', 'get', $addressParams);
      if (!empty($result['values'])) {
        return;
      }
    }
    $addressParams['update_current_employer'] = 0;
    $addressParams['add_relationship'] = 0;
    $addressParams[PHONE_ID_CUSTOM_FIELD] = $values['phone_id'][$rowNumber];
    $address = civicrm_api3('Address', 'create', $addressParams);
    return [$address['id'], $addressParams];
  }

  /**
   * Validation Rule.
   *
   * @param array $params
   *
   * @return array|bool
   */
  public static function usernameRule($cid) {
    // Check if there is a UFMatch, if there is, that means there is a CMS ID associated the account.
    $ufId = CRM_Core_DAO::singleValueQuery("SELECT uf_id FROM civicrm_uf_match WHERE contact_id = %1", [1 => [$cid, "Integer"]]);
    if (!empty($ufId)) {
      return TRUE;
    }
    // Check if the CMS has an account with the same email.
    $email = CRM_Core_DAO::singleValueQuery("SELECT email FROM civicrm_email WHERE contact_id = %1 AND is_primary = 1", [1 => [$cid, "Integer"]]);
    if (!empty($email)) {
      $config = CRM_Core_Config::singleton();
      $errors = array();
      $check_params = array(
        'mail' => $email,
      );
      $config->userSystem->checkUserNameEmailExists($check_params, $errors, 'mail');

      return !empty($errors) ? TRUE : FALSE;
    }
    return FALSE;
  }

  /**
   * Validation Rule.
   *
   * @param array $params
   *
   * @return array|bool
   */
  public static function emailRule($cid) {
    // Check if the user has a valid email.
    $email = CRM_Core_DAO::singleValueQuery("SELECT email FROM civicrm_email WHERE contact_id = %1 AND is_primary = 1 AND on_hold <> 1", [1 => [$cid, "Integer"]]);
    if (!empty($email)) {
      // 2nd level check. Check UF table if email exists
      $emailExists = CRM_Core_DAO::singleValueQuery("SELECT uf_name FROM civicrm_uf_match WHERE uf_name = %1", [1 => [$email, "String"]]);
      if (!empty($emailExists)) {
        return TRUE;
      }
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Generate a safe Drupal user name for use
   * @param array $nameDetails
   */
  public static function generateDrupalUserName($nameDetails) {
    $nameFields = ['first_name', 'last_name'];
    foreach ($nameFields as $field) {
      $nameDetails[$field] = strtolower(preg_replace('/[^a-zA-Z0-9\/\s]+/', '', $nameDetails[$field]));
    }
    // Check if username is present, and append count to it if it is.
    $con = \Drupal\Core\Database\Database::getConnection();
    $query = $con->select('users_field_data', 'u')
      ->fields('u', ['name']);
    $query->condition('name', $query->escapeLike($nameDetails['first_name'] . '.' . $nameDetails['last_name']) . "%", 'LIKE');
    $result = $query->execute()->fetchAll();
    if (!empty($result)) {
      $userCount = count($result) + 1;
      return $nameDetails['first_name'] . '.' . $nameDetails['last_name'] . $userCount;
    }
    return $nameDetails['first_name'] . '.' . $nameDetails['last_name'];
  }

  public static function createUserAccount($cid) {
    // We create the user account for the primary contact.
    if (empty($cid)) {
      return FALSE;
    }
    $name = CRM_Core_DAO::executeQuery("SELECT id, first_name, last_name, display_name
          FROM civicrm_contact WHERE id = %1", [1 => [$cid, "Integer"]])->fetchAll()[0];
    $userName = self::generateDrupalUserName($name);
    if (self::usernameRule($cid)) {
      return FALSE;
    }
    if (self::emailRule($cid)) {
      return FALSE;
    }
    // Reset $_post.
    $_POST = [];
    $params = [
      'cms_name' => $userName,
      'cms_pass' => 'changeme',
      'cms_confirm_pass' => 'changeme',
      'email' => CRM_Core_DAO::singleValueQuery("SELECT email FROM civicrm_email WHERE contact_id = %1 AND is_primary = 1", [1 => [$cid, "Integer"]]),
      'contactID' => $cid,
      'name' => $name['display_name'],
      'notify' => TRUE,
    ];
    $ufId = CRM_Core_BAO_CMSUser::create($params, 'email');
    if (empty($ufId)) {
      return FALSE;
    }
    return TRUE;
  }

  function setStatus($oldStatus = NULL, $cid, $submitValues) {
    if (!empty($cid)) {
      $submitKeys = array_keys($submitValues);
      $key = preg_grep('/^' . STATUS . '_[\d]*/', $submitKeys);
      $newStatus = reset($key);
      if ($oldStatus != "Approved" && CRM_Utils_Array::value($newStatus, $submitValues) == 'Approved') {
        // Fetch primary contact.
        $relationship = civicrm_api3('Relationship', 'get', [
          'contact_id_b' => $cid,
          'relationship_type_id' => PRIMARY_CONTACT_REL,
          'return' => 'contact_id_a',
          'is_active' => 1,
        ]);
        if ($relationship['count'] > 0 && !empty($relationship['values'][$relationship['id']]['contact_id_a'])) {
          $primaryCid = $relationship['values'][$relationship['id']]['contact_id_a'];
        }
        if (empty($primaryCid)) {
          CRM_Core_Session::setStatus(ts('No primary contact found for this service listing! Please specify a primary contact and re-approve the listing.'));
          return FALSE;
        }
        // Create drupal account if not exists.
        if (!self::createUserAccount($primaryCid)) {
          // Set status message indicating that user account creation was unsuccessful if the user account doesn't exist.
          if (empty(CRM_Core_DAO::singleValueQuery("SELECT uf_id FROM civicrm_uf_match WHERE contact_id = %1", [1 => [$primaryCid, "Integer"]]))) {
            $userUrl = CRM_Utils_System::url('civicrm/contact/view/useradd', "reset=1&action=add&cid=$primaryCid", TRUE);
            CRM_Core_Session::setStatus(ts('There was an error creating the user account. Please proceed to add one <a href="%1">here</a>.', [1 => $userUrl]), ts('Warning'), 'alert');
          }
        }

        // Send Mail
        self::sendMessage($primaryCid, APPROVED_MESSAGE);

        $activityID = civicrm_api3('Activity', 'get', [
          'source_contact_id' => $cid,
          'activity_type_id' => "service_listing_created",
          'status_id' => 'Scheduled',
          'sequential' => 1,
        ])['values'][0]['id'];
        if ($activityID) {
          civicrm_api3('Activity', 'create', [
            'id' => $activityID,
            'status_id' => 'Completed',
          ]);
        }
        // Add URL for listing to the service listing record.
        $url = CRM_Utils_System::url("service-listing/$cid", NULL, TRUE);
        civicrm_api3('Contact', 'create', ['id' => $cid, LISTING_URL => $url]);
      }
      if ($oldStatus != $newStatus) {
        civicrm_api3('Activity', 'create', [
          'source_contact_id' => $cid,
          'activity_type_id' => "provider_status_changed",
          'subject' => sprintf("Application status changed to %s", CRM_Utils_Array::value($newStatus, $submitValues)),
          'activity_status_id' => 'Completed',
          'details' => '<a class="action-item crm-hover-button" href="https://www.autismontario.com/civicrm/contact/view?cid=' . $cid . '">View Applicant</a>',
          'target_id' => $cid,
          'assignee_id' => CRM_Core_Session::singleton()->getLoggedInContactID() ?: NULL,
        ]);
      }
    }
  }

}

use CRM_Aoservicelisting_ExtensionUtil as E;

/**
 * (Delegated) Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config
 */
function _aoservicelisting_civix_civicrm_config(&$config = NULL) {
  static $configured = FALSE;
  if ($configured) {
    return;
  }
  $configured = TRUE;

  $template =& CRM_Core_Smarty::singleton();

  $extRoot = dirname(__FILE__) . DIRECTORY_SEPARATOR;
  $extDir = $extRoot . 'templates';

  if (is_array($template->template_dir)) {
    array_unshift($template->template_dir, $extDir);
  }
  else {
    $template->template_dir = [$extDir, $template->template_dir];
  }

  $include_path = $extRoot . PATH_SEPARATOR . get_include_path();
  set_include_path($include_path);
}

/**
 * (Delegated) Implements hook_civicrm_xmlMenu().
 *
 * @param $files array(string)
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function _aoservicelisting_civix_civicrm_xmlMenu(&$files) {
  foreach (_aoservicelisting_civix_glob(__DIR__ . '/xml/Menu/*.xml') as $file) {
    $files[] = $file;
  }
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function _aoservicelisting_civix_civicrm_install() {
  _aoservicelisting_civix_civicrm_config();
  if ($upgrader = _aoservicelisting_civix_upgrader()) {
    $upgrader->onInstall();
  }
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function _aoservicelisting_civix_civicrm_postInstall() {
  _aoservicelisting_civix_civicrm_config();
  if ($upgrader = _aoservicelisting_civix_upgrader()) {
    if (is_callable([$upgrader, 'onPostInstall'])) {
      $upgrader->onPostInstall();
    }
  }
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function _aoservicelisting_civix_civicrm_uninstall() {
  _aoservicelisting_civix_civicrm_config();
  if ($upgrader = _aoservicelisting_civix_upgrader()) {
    $upgrader->onUninstall();
  }
}

/**
 * (Delegated) Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function _aoservicelisting_civix_civicrm_enable() {
  _aoservicelisting_civix_civicrm_config();
  if ($upgrader = _aoservicelisting_civix_upgrader()) {
    if (is_callable([$upgrader, 'onEnable'])) {
      $upgrader->onEnable();
    }
  }
}

/**
 * (Delegated) Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 * @return mixed
 */
function _aoservicelisting_civix_civicrm_disable() {
  _aoservicelisting_civix_civicrm_config();
  if ($upgrader = _aoservicelisting_civix_upgrader()) {
    if (is_callable([$upgrader, 'onDisable'])) {
      $upgrader->onDisable();
    }
  }
}

/**
 * (Delegated) Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function _aoservicelisting_civix_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  if ($upgrader = _aoservicelisting_civix_upgrader()) {
    return $upgrader->onUpgrade($op, $queue);
  }
}

/**
 * @return CRM_Aoservicelisting_Upgrader
 */
function _aoservicelisting_civix_upgrader() {
  if (!file_exists(__DIR__ . '/CRM/Aoservicelisting/Upgrader.php')) {
    return NULL;
  }
  else {
    return CRM_Aoservicelisting_Upgrader_Base::instance();
  }
}

/**
 * Search directory tree for files which match a glob pattern.
 *
 * Note: Dot-directories (like "..", ".git", or ".svn") will be ignored.
 * Note: In Civi 4.3+, delegate to CRM_Utils_File::findFiles()
 *
 * @param string $dir base dir
 * @param string $pattern , glob pattern, eg "*.txt"
 *
 * @return array(string)
 */
function _aoservicelisting_civix_find_files($dir, $pattern) {
  if (is_callable(['CRM_Utils_File', 'findFiles'])) {
    return CRM_Utils_File::findFiles($dir, $pattern);
  }

  $todos = [$dir];
  $result = [];
  while (!empty($todos)) {
    $subdir = array_shift($todos);
    foreach (_aoservicelisting_civix_glob("$subdir/$pattern") as $match) {
      if (!is_dir($match)) {
        $result[] = $match;
      }
    }
    if ($dh = opendir($subdir)) {
      while (FALSE !== ($entry = readdir($dh))) {
        $path = $subdir . DIRECTORY_SEPARATOR . $entry;
        if ($entry{0} == '.') {
        }
        elseif (is_dir($path)) {
          $todos[] = $path;
        }
      }
      closedir($dh);
    }
  }
  return $result;
}
/**
 * (Delegated) Implements hook_civicrm_managed().
 *
 * Find any *.mgd.php files, merge their content, and return.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function _aoservicelisting_civix_civicrm_managed(&$entities) {
  $mgdFiles = _aoservicelisting_civix_find_files(__DIR__, '*.mgd.php');
  sort($mgdFiles);
  foreach ($mgdFiles as $file) {
    $es = include $file;
    foreach ($es as $e) {
      if (empty($e['module'])) {
        $e['module'] = E::LONG_NAME;
      }
      if (empty($e['params']['version'])) {
        $e['params']['version'] = '3';
      }
      $entities[] = $e;
    }
  }
}

/**
 * (Delegated) Implements hook_civicrm_caseTypes().
 *
 * Find any and return any files matching "xml/case/*.xml"
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function _aoservicelisting_civix_civicrm_caseTypes(&$caseTypes) {
  if (!is_dir(__DIR__ . '/xml/case')) {
    return;
  }

  foreach (_aoservicelisting_civix_glob(__DIR__ . '/xml/case/*.xml') as $file) {
    $name = preg_replace('/\.xml$/', '', basename($file));
    if ($name != CRM_Case_XMLProcessor::mungeCaseType($name)) {
      $errorMessage = sprintf("Case-type file name is malformed (%s vs %s)", $name, CRM_Case_XMLProcessor::mungeCaseType($name));
      throw new CRM_Core_Exception($errorMessage);
    }
    $caseTypes[$name] = [
      'module' => E::LONG_NAME,
      'name' => $name,
      'file' => $file,
    ];
  }
}

/**
 * (Delegated) Implements hook_civicrm_angularModules().
 *
 * Find any and return any files matching "ang/*.ang.php"
 *
 * Note: This hook only runs in CiviCRM 4.5+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function _aoservicelisting_civix_civicrm_angularModules(&$angularModules) {
  if (!is_dir(__DIR__ . '/ang')) {
    return;
  }

  $files = _aoservicelisting_civix_glob(__DIR__ . '/ang/*.ang.php');
  foreach ($files as $file) {
    $name = preg_replace(':\.ang\.php$:', '', basename($file));
    $module = include $file;
    if (empty($module['ext'])) {
      $module['ext'] = E::LONG_NAME;
    }
    $angularModules[$name] = $module;
  }
}

/**
 * (Delegated) Implements hook_civicrm_themes().
 *
 * Find any and return any files matching "*.theme.php"
 */
function _aoservicelisting_civix_civicrm_themes(&$themes) {
  $files = _aoservicelisting_civix_glob(__DIR__ . '/*.theme.php');
  foreach ($files as $file) {
    $themeMeta = include $file;
    if (empty($themeMeta['name'])) {
      $themeMeta['name'] = preg_replace(':\.theme\.php$:', '', basename($file));
    }
    if (empty($themeMeta['ext'])) {
      $themeMeta['ext'] = E::LONG_NAME;
    }
    $themes[$themeMeta['name']] = $themeMeta;
  }
}

/**
 * Glob wrapper which is guaranteed to return an array.
 *
 * The documentation for glob() says, "On some systems it is impossible to
 * distinguish between empty match and an error." Anecdotally, the return
 * result for an empty match is sometimes array() and sometimes FALSE.
 * This wrapper provides consistency.
 *
 * @link http://php.net/glob
 * @param string $pattern
 *
 * @return array, possibly empty
 */
function _aoservicelisting_civix_glob($pattern) {
  $result = glob($pattern);
  return is_array($result) ? $result : [];
}

/**
 * Inserts a navigation menu item at a given place in the hierarchy.
 *
 * @param array $menu - menu hierarchy
 * @param string $path - path to parent of this item, e.g. 'my_extension/submenu'
 *    'Mailing', or 'Administer/System Settings'
 * @param array $item - the item to insert (parent/child attributes will be
 *    filled for you)
 *
 * @return bool
 */
function _aoservicelisting_civix_insert_navigation_menu(&$menu, $path, $item) {
  // If we are done going down the path, insert menu
  if (empty($path)) {
    $menu[] = [
      'attributes' => array_merge([
        'label'      => CRM_Utils_Array::value('name', $item),
        'active'     => 1,
      ], $item),
    ];
    return TRUE;
  }
  else {
    // Find an recurse into the next level down
    $found = FALSE;
    $path = explode('/', $path);
    $first = array_shift($path);
    foreach ($menu as $key => &$entry) {
      if ($entry['attributes']['name'] == $first) {
        if (!isset($entry['child'])) {
          $entry['child'] = [];
        }
        $found = _aoservicelisting_civix_insert_navigation_menu($entry['child'], implode('/', $path), $item);
      }
    }
    return $found;
  }
}

/**
 * (Delegated) Implements hook_civicrm_navigationMenu().
 */
function _aoservicelisting_civix_navigationMenu(&$nodes) {
  if (!is_callable(['CRM_Core_BAO_Navigation', 'fixNavigationMenu'])) {
    _aoservicelisting_civix_fixNavigationMenu($nodes);
  }
}

/**
 * Given a navigation menu, generate navIDs for any items which are
 * missing them.
 */
function _aoservicelisting_civix_fixNavigationMenu(&$nodes) {
  $maxNavID = 1;
  array_walk_recursive($nodes, function($item, $key) use (&$maxNavID) {
    if ($key === 'navID') {
      $maxNavID = max($maxNavID, $item);
    }
  });
  _aoservicelisting_civix_fixNavigationMenuItems($nodes, $maxNavID, NULL);
}

function _aoservicelisting_civix_fixNavigationMenuItems(&$nodes, &$maxNavID, $parentID) {
  $origKeys = array_keys($nodes);
  foreach ($origKeys as $origKey) {
    if (!isset($nodes[$origKey]['attributes']['parentID']) && $parentID !== NULL) {
      $nodes[$origKey]['attributes']['parentID'] = $parentID;
    }
    // If no navID, then assign navID and fix key.
    if (!isset($nodes[$origKey]['attributes']['navID'])) {
      $newKey = ++$maxNavID;
      $nodes[$origKey]['attributes']['navID'] = $newKey;
      $nodes[$newKey] = $nodes[$origKey];
      unset($nodes[$origKey]);
      $origKey = $newKey;
    }
    if (isset($nodes[$origKey]['child']) && is_array($nodes[$origKey]['child'])) {
      _aoservicelisting_civix_fixNavigationMenuItems($nodes[$origKey]['child'], $maxNavID, $nodes[$origKey]['attributes']['navID']);
    }
  }
}

/**
 * (Delegated) Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function _aoservicelisting_civix_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  $settingsDir = __DIR__ . DIRECTORY_SEPARATOR . 'settings';
  if (!in_array($settingsDir, $metaDataFolders) && is_dir($settingsDir)) {
    $metaDataFolders[] = $settingsDir;
  }
}

/**
 * (Delegated) Implements hook_civicrm_entityTypes().
 *
 * Find any *.entityType.php files, merge their content, and return.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */

function _aoservicelisting_civix_civicrm_entityTypes(&$entityTypes) {
  $entityTypes = array_merge($entityTypes, array (
  ));
}
