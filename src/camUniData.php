<?php

/* Example usage:

# Valid CRSID check, checking (only) syntax
$result = camUniData::validCrsid ('abc01');
echo $result;

# Get lookup data - can accept an array or string
$person = camUniData::lookupUser ('sqpr1');
print_r ($person);
$people = camUniData::lookupUsers (array ('xyz12', 'sqpr1'));
print_r ($people);

# Documentation
https://www.lookup.cam.ac.uk/doc/ws-doc/
https://www.lookup.cam.ac.uk/openapi-3.0.json

*/


/*
	# Note for future
	# If adding a regexp for matching a Cambridge University e-mail, bear in mind that the following formats listed below are also supported.
	# See e-mail from fanf2 dated Wed, 22 Jul 2009 20:26:37 +0100, Message-ID: <alpine.LSU.2.00.0907222009070.17246@hermes-2.csi.cam.ac.uk> describing this
	crsid+detail@cam.ac.uk
	crsid--detail@cam.ac.uk
	crsid+detail@ucs.cam.ac.uk
	crsid--detail@ucs.cam.ac.uk
	forename.surname+detail@ucs.cam.ac.uk
	forename.surname--detail@ucs.cam.ac.uk
*/


# Class containing Cambridge University -specific data-orientated functions
class camUniData
{
	# Function to check a valid CRSID - checks syntax ONLY, not whether the CRSID is active or exists
	public static function validCrsid ($crsid, $mustBeLowerCase = false)
	{
		# Get the regexp
		$regexp = self::crsidRegexp ($mustBeLowerCase);
		
		# Return the result as a boolean
		return (preg_match ('/' . $regexp . '/', $crsid));
	}
	
	
	# Function to return the regexp for a CRSID
	public static function crsidRegexp ($mustBeLowerCase = false)
	{
		# Define the letter part
		$letters = ($mustBeLowerCase ? 'a-z' : 'a-zA-Z');
		
		# Define the regexp - as defined by fanf2 in Message-ID: <cEj*NC0dr@news.chiark.greenend.org.uk> to ucam.comp.misc on 060412
		# NB: ^([a-z]{2,5})([1-9])([0-9]{0,4})$ doesn't deal with the few people with simply four letter CRSIDs
		$regexp = '^[' . $letters . '][' . $letters . '0-9]{1,7}$';
		
		# Return the regexp
		return $regexp;
	}
	
	
	# Function to get details of a user from Lookup
	# E.g. https://www.lookup.cam.ac.uk/api/v1/person/crsid/spqr1?format=json&fetch=displayName,email,jdInstid,jdCollege,title,labeledURI,surname,universityPhone
	public static function lookupUser ($crsid, $authUsername = 'anonymous', $authPassword = '')
	{
		# Get the data
		$method = '/person/crsid/' . $crsid;
		return self::getSingular ($method, $authUsername, $authPassword);
	}
	
	
	# Function to get details of multiple users from Lookup; only active users will be returned
	# NB Due to URL length limitations, the number of people that this method may fetch is typically limited to a few hundred; see: https://www.lookup.cam.ac.uk/openapi-3.0.json
	# E.g. https://www.lookup.cam.ac.uk/api/v1/person/list?crsids=spqr1,xyz9999&format=json&fetch=displayName,email,jdInstid,jdCollege,title,labeledURI,surname,universityPhone
	public static function lookupUsers ($crsids, $authUsername = 'anonymous', $authPassword = '')
	{
		# Get the data
		$method = '/person/list?crsids=' . implode (',', $crsids);
		return self::getMultiple ($method, $authUsername, $authPassword);
	}
	
	
	# Function to get details of users in a group from Lookup; only active users will be returned
	# E.g. https://www.lookup.cam.ac.uk/api/v1/inst/BOTOLPH/members?format=json&fetch=displayName,email,jdInstid,jdCollege,title,labeledURI,surname,universityPhone
	public static function lookupUsersInGroup ($group, $authUsername = 'anonymous', $authPassword = '')
	{
		# Get the data
		$method = '/inst/' . $group . '/members';
		return self::getMultiple ($method, $authUsername, $authPassword);
	}
	
	
	# Function to get details of institutions from Lookup
	# E.g. https://www.lookup.cam.ac.uk/api/v1/inst/list?instids=BOTOLPH,PORTERHOUSE&format=json
	public static function lookupInstitutions ($institutionIds, $authUsername = 'anonymous', $authPassword = '')
	{
		# Assemble the URL
		$method = '/inst/list?instids=' . implode (',', $institutionIds);
		$url = "https://{$authUsername}:{$authPassword}@www.lookup.cam.ac.uk/api/v1" . $method . (substr_count ($method, '?') ? '&' : '?') . "&format=json";
		
		# Get the data
		$context = stream_context_create (array ('http' => array ('timeout' => 2)));
		if (!$json = file_get_contents ($url, false, $context)) {return array ();}
		$json = json_decode ($json, true);
		
		# End if no match
		if (!isSet ($json['result']['institutions'])) {return array ();}
		
		# Format as key/value pairs
		$institutions = array ();
		foreach ($json['result']['institutions'] as $institution) {
			$id = $institution['instid'];
			$institutions[$id] = $institution['name'];
		}
		
		# Return the institutions
		return $institutions;
	}
	
	
	# Helper function to get singular item; see: https://www.lookup.cam.ac.uk/doc/ws-doc/ and attributes at https://www.lookup.cam.ac.uk/api/v1/person/all-attr-schemes
	/* private */ public static function getSingular ($method, $authUsername, $authPassword)
	{
		# Assemble the URL
		$attributes = 'displayName,email,jdInstid,jdCollege,title,labeledURI,surname,universityPhone';
		$url = "https://{$authUsername}:{$authPassword}@www.lookup.cam.ac.uk/api/v1" . $method . (substr_count ($method, '?') ? '&' : '?') . "fetch={$attributes}&format=json";
		
		# Get the data
		$context = stream_context_create (array ('http' => array ('timeout' => 2)));
		if (!$json = file_get_contents ($url, false, $context)) {return array ();}
		$json = json_decode ($json, true);
		
		# End if no match
		if (!isSet ($json['result']['person'])) {return array ();}
		
		# Format the user
		$user = self::formatUser ($json['result']['person']);
		
		# Substitute the institution IDs for institution names
		$users = self::substituteInstitutionIds (array ($user['username'] => $user), $authUsername, $authPassword);
		$user = $users[$user['username']];
		
		# Return the user
		return $user;
	}
	
	
	# Function to get details of users from Lookup; see: https://www.lookup.cam.ac.uk/doc/ws-doc/ and attributes at https://www.lookup.cam.ac.uk/api/v1/person/all-attr-schemes
	public static function getMultiple ($method, $authUsername = 'anonymous', $authPassword = '')
	{
		# Assemble the URL
		$attributes = 'displayName,email,jdInstid,jdCollege,title,labeledURI,surname,universityPhone';
		$url = "https://{$authUsername}:{$authPassword}@www.lookup.cam.ac.uk/api/v1" . $method . (substr_count ($method, '?') ? '&' : '?') . "fetch={$attributes}&format=json";
		
		# Get the data
		$context = stream_context_create (array ('http' => array ('timeout' => 2)));
		if (!$json = file_get_contents ($url, false, $context)) {return array ();}
		$json = json_decode ($json, true);
		
		# End if no match
		if (!isSet ($json['result']['people'])) {return array ();}
		
		# Format the users, skipping inactive users
		$users = array ();
		foreach ($json['result']['people'] as $person) {
			if ($person['cancelled']) {continue;}		// As per original LDAP implementation - may wish to make this optional in future
			$username = $person['identifier']['value'];
			$users[$username] = self::formatUser ($person);
		}
		
		# Substitute the institution IDs for institution names
		$users = self::substituteInstitutionIds ($users, $authUsername, $authPassword);
		
		# Return the users
		return $users;
	}
	
	
	# Helper function to format a person from the data structure
	/* private */ public static function formatUser ($person)
	{
		# Rearrange the attributes as key/value pairs, with only the first in any group used
		$attributes = array ();
		foreach ($person['attributes'] as $attribute) {
			$key = $attribute['scheme'];
			if (!array_key_exists ($key, $attributes)) {	// Use only the first to appear, e.g. labeledURI may appear more than once
				$attributes[$key] = $attribute['value'];
			}
		}
		
		# Arrange the data
		$user = array (
			'username'		=> $person['identifier']['value'],
			'name'			=> $attributes['displayName'],
			'email'			=> (isSet ($attributes['email']) ? $attributes['email'] : $person['identifier']['value'] . '@cam.ac.uk'),
			'department'	=> (isSet ($attributes['jdInstid']) ? $attributes['jdInstid'] : false),
			'college'		=> (isSet ($attributes['jdCollege']) ? $attributes['jdCollege'] : false),
			'title'			=> (isSet ($attributes['title']) ? $attributes['title'] : false),		// Post title, not honorific
			'website'		=> (isSet ($attributes['labeledURI']) ? $attributes['labeledURI'] : false),
			'surname'		=> (isSet ($attributes['surname']) ? $attributes['surname'] : false),
			'telephone'		=> (isSet ($attributes['universityPhone']) ? $attributes['universityPhone'] : false),
		);
		
		# Trim values
		foreach ($user as $key => $value) {
			$user[$key] = trim ($value);
		}
		
		# Compute the forename by chopping off the surname
		$user['forename'] = false;
		if ($user['name'] && $user['surname']) {
			$delimiter = '/';
			$user['forename'] = trim (preg_replace ($delimiter . preg_quote ($user['surname'], $delimiter) . '$' . $delimiter, '', $user['name']));
		}
		
		# Return the user
		return $user;
	}
	
	
	# Function to substitute the institution IDs for institution names; this is done on a batch basis to avoid multiple calls to the institutions API
	/* private */ public static function substituteInstitutionIds ($users, $authUsername, $authPassword)
	{
		# Get all the institution IDs in the dataset
		$institutionIds = array ();
		foreach ($users as $username => $user) {
			if (isSet ($user['department']) && strlen ($user['department'])) {
				$institutionIds[] = $user['department'];
			}
			if (isSet ($user['college']) && strlen ($user['college'])) {
				$institutionIds[] = $user['college'];
			}
		}
		array_unique ($institutionIds);
		
		# Get the institutions data
		$institutions = self::lookupInstitutions ($institutionIds, $authUsername, $authPassword);
		
		# Perform the substitutions
		foreach ($users as $username => $user) {
			if (isSet ($user['department']) && strlen ($user['department'])) {
				if (isSet ($institutions[$user['department']])) {
					$users[$username]['department'] = $institutions[$user['department']];
				}
			}
			if (isSet ($user['college']) && strlen ($user['college'])) {
				if (isSet ($institutions[$user['college']])) {
					$users[$username]['college'] = $institutions[$user['college']];
				}
			}
		}
		
		# Return the modified data
		return $users;
	}
	
	
	# Function to get a user list formatted for search-as-you-type from lookup; see: https://www.lookup.cam.ac.uk/doc/ws-doc/ and the 'search' method at https://www.lookup.cam.ac.uk/doc/ws-javadocs/uk/ac/cam/ucs/ibis/methods/PersonMethods.html
	public static function lookupSearch ($term, $autocompleteFormat = false, $indexByUsername = false)
	{
		# Define the URL format, with %s placeholder
		$urlFormat = 'https://anonymous:@www.lookup.cam.ac.uk/api/v1/person/search?attributes=displayName,registeredName,surname&limit=10&orderBy=identifier&format=json&query=%s';
		$url = sprintf ($urlFormat, $term);
		
		# Get the data
		$context = stream_context_create (array ('http' => array ('timeout' => 2)));
		if (!$json = file_get_contents ($url, false, $context)) {return array ();}
		
		# Decode the JSON
		$json = json_decode ($json, true);
		
		# Find the results
		if (!isSet ($json['result']) || !array_key_exists ('people', $json['result'])) {return array ();}		// Should only happen if the format has changed - an empty result will still have this structure
		$people = $json['result']['people'];
		
		# End if none
		if (!$people) {return array ();}
		
		# Arrange as array(username=>name,...)
		$data = array ();
		foreach ($people as $person) {
			$key = $person['identifier']['value'];
			$value = $key . ' (' . $person['visibleName'] . ')';
			$data[$key] = $value;
		}
		
		# For autocomplete format, arrange the data; see http://af-design.com/blog/2010/05/12/using-jquery-uis-autocomplete-to-populate-a-form/ which documents this
		if ($autocompleteFormat) {
			$dataAutocompleteFormat = array ();
			$isTokenisedFormat = ($autocompleteFormat === 'tokenised');	// Older format
			foreach ($data as $value => $label) {
				if ($isTokenisedFormat) {	// Older format
					$dataAutocompleteFormat[$value] = array ('id' => $value, 'name' => $value);	// q=searchterm&tokenised=true
				} else {
					$dataAutocompleteFormat[$value] = array ('label' => $label, 'value' => $value);	// term=searchterm
				}
			}
			$data = $dataAutocompleteFormat;
		}
		
		# Strip keys if required
		if (!$indexByUsername) {
			$data = array_values ($data);
		}
		
		# Return the data
		return $data;
	}
	
	
	# Autocomplete wrapper
	public static function autocompleteNamesUrlSource ()
	{
		#!# Needs to be generalised
		return 'https://intranet.geog.cam.ac.uk/contacts/database/data.html?source=localstaff,lookup';
	}
}

?>
