<?php

class CRM_Servicelisting_Form_Report_ServiceList extends CRM_Report_Form_Contact_Summary {
  protected $_customGroupExtends = array(
      'Contact',
      'Individual',
      'Household',
      'Organization',
      'Relationship',
      'Activity'
    );

  public function __construct() {
    parent::__construct();
    $this->_columns['civicrm_contact']['fields']['created_date'] = [
      'title' => ts('Created Date'),
      'default' => FALSE,
      'dbAlias' => 'DATE(contact_civireport.created_date)'
    ];
    unset($this->_columns['civicrm_contact']['fields']['employer_id']);
    $this->_columns['civicrm_contact']['fields']['employer'] = [
      'title' => ts('Employer(s)'),
      'dbAlias' => "'1'",
      'default' => FALSE,
    ];
    $this->_columns['civicrm_contact']['order_bys']['created_date'] = [
      'name' => 'created_date',
      'title' => ts('Created Date'),
    ];
    $customGroup = civicrm_api3('CustomGroup', 'getsingle', ['custom_group_id' => CAMP_CG]);
    $customFields = civicrm_api3('CustomField', 'get', ['custom_group_id' => CAMP_CG])['values'];
    foreach ($customFields as $customField) {
      $this->_columns[$customGroup['table_name']]['fields']['custom_' . $customField['id']]['dbAlias'] = "GROUP_CONCAT(DISTINCT {$customField['column_name']})";
    }
  }

  public function from() {
    $this->_from = "
        FROM civicrm_contact {$this->_aliases['civicrm_contact']} {$this->_aclFrom} ";
    $this->joinAddressFromContact();
    $this->joinPhoneFromContact();
    $this->joinEmailFromContact();
    $this->joinCountryFromAddress();
  }

  public function where() {
    parent::where();
    $this->_where = str_replace('AND ( contact_civireport.is_deleted = 0 )', '', $this->_where);
  }

  public function groupBy() {
    $this->_groupBy = " GROUP BY {$this->_aliases['civicrm_contact']}.id ";
  }

  /**
   * Alter the way in which custom data fields are displayed.
   *
   * @param array $rows
   */
  public function alterCustomDataDisplay(&$rows) {
    // custom code to alter rows having custom values
    if (empty($this->_customGroupExtends)) {
      return;
    }

    $customFields = [];
    $customFieldIds = $fileFields = [];
    foreach ($this->_params['fields'] as $fieldAlias => $value) {
      if ($fieldId = CRM_Core_BAO_CustomField::getKeyID($fieldAlias)) {
        $customFieldIds[$fieldAlias] = $fieldId;
      }
    }
    if (empty($customFieldIds)) {
      return;
    }

    // skip for type date and ContactReference since date format is already handled
    $query = "
SELECT cg.table_name, cf.id, cf.data_type
FROM  civicrm_custom_field cf
INNER JOIN civicrm_custom_group cg ON cg.id = cf.custom_group_id
WHERE cg.extends IN ('" . implode("','", $this->_customGroupExtends) . "') AND
      cg.is_active = 1 AND
      cf.is_active = 1 AND
      cf.is_searchable = 1 AND
      cf.data_type   NOT IN ('ContactReference', 'Date') AND
      cf.id IN (" . implode(",", $customFieldIds) . ")";

    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $customFields[$dao->table_name . '_custom_' . $dao->id] = $dao->id;
      if ($dao->data_type == 'File') {
        $fileFields[$dao->table_name . '_custom_' . $dao->id] = $dao->id;
      }
    }
    $dao->free();

    $entryFound = FALSE;
    foreach ($rows as $rowNum => $row) {
      foreach ($row as $tableCol => $val) {
        if (array_key_exists($tableCol, $customFields)) {
          if (array_key_exists($tableCol, $fileFields)) {
            if (!CRM_Utils_Rule::integer($val)) {
              continue;
            }
            $currentAttachmentInfo = CRM_Core_BAO_File::getEntityFile('*', $val);
            foreach ($currentAttachmentInfo as $fileKey => $fileValue) {
              $rows[$rowNum][$tableCol] = ($this->_outputMode == 'csv') ? CRM_Utils_System::url($fileValue['url'], NULL, TRUE) : sprintf("<a href='%s'>%s</a>", CRM_Utils_System::url($fileValue['url'], NULL, TRUE), $fileValue['cleanName']);
            }
          }
          else {
            $rows[$rowNum][$tableCol] = CRM_Core_BAO_CustomField::displayValue($val, $customFields[$tableCol]);
          }
          $entryFound = TRUE;
        }
      }

      // skip looking further in rows, if first row itself doesn't
      // have the column we need
      if (!$entryFound) {
        break;
      }
    }
  }

  /**
   * Alter display of rows.
   *
   * Iterate through the rows retrieved via SQL and make changes for display purposes,
   * such as rendering contacts as links.
   *
   * @param array $rows
   *   Rows generated by SQL, with an array for each row.
   */
  public function alterDisplay(&$rows) {
    $dateFormat = CRM_Core_Config::singleton()->dateformatTime;
    $entryFound = FALSE;

    $columnOrder = $columnHeaders = [];

    foreach ([SERVICELISTING_CG, CAMP_CG] as $customGroupID) {
      $customGroup = civicrm_api3('CustomGroup', 'getsingle', ['custom_group_id' => $customGroupID]);
      $tableName = $customGroup['table_name'];
      $customFields = civicrm_api3('CustomField', 'get', ['custom_group_id' => $customGroupID, 'options' => ['sort' => "weight ASC"],])['values'];
      foreach ($customFields as $customField) {
        $columnOrder[] = $tableName . '_custom_' . $customField['id'];
      }
    }
    foreach ($columnOrder as $name) {
      if (array_key_exists($name, $this->_columnHeaders)) {
        $columnHeaders[$name] = $this->_columnHeaders[$name];
        unset($this->_columnHeaders[$name]);
      }
    }
    $this->_columnHeaders = array_merge($this->_columnHeaders, $columnHeaders);

    foreach ($rows as $rowNum => $row) {

      foreach ([
      'civicrm_contact_employer' => 'organization_name',
      'civicrm_address_address_city' => 'city',
      'civicrm_address_address_street_address' => 'street_address',
      'civicrm_address_postal_code' => 'postal_code',
      'civicrm_phone_phone' => 'phone',
      ] as $column => $name) {
        if (!empty($row[$column])) {
          $rows[$rowNum][$column] = implode(', ', array_filter(CRM_Utils_Array::collect($name, $employerInfo)));
        }
      }

      // make count columns point to detail report
      // convert sort name to links
      if (array_key_exists('civicrm_contact_sort_name', $row) &&
        array_key_exists('civicrm_contact_id', $row)
      ) {
        $rows[$rowNum]['civicrm_contact_sort_name_link'] = CRM_Utils_System::url('civicrm/contact/view', 'reset=1&cid=' . $row['civicrm_contact_id']);
        $rows[$rowNum]['civicrm_contact_sort_name_hover'] = ts('View Contact Detail Report for this contact');
        $entryFound = TRUE;
      }

      // Handle ID to label conversion for contact fields
      $entryFound = $this->alterDisplayContactFields($row, $rows, $rowNum, 'contact/summary', 'View Contact Summary') ? TRUE : $entryFound;

      // skip looking further in rows, if first row itself doesn't
      // have the column we need
      if (!$entryFound) {
        break;
      }
    }
  }

}
