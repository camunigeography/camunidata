<?php

/* Example usage:

# Load required libraries
require_once ('camUniData.php');

# Valid CRSID check
$result = camUniData::validCrsid ('abc01');
echo $result;

# Get lookup data - can accept an array or string
$person = camUniData::getLookupData ('mvl22');
print_r ($person);
$people = camUniData::getLookupData (array ('mb425', 'mvl22'));
print_r ($people);

*/


# Version 1.0.0

# Class containing Cambridge University -specific data-orientated functions
class camUniData
{
	# Function to check a valid CRSID - checks syntax ONLY, not whether the CRSID is active or exists
	function validCrsid ($crsid, $mustBeLowerCase = false)
	{
		# Define the letter part
		$letters = ($mustBeLowerCase ? 'a-z' : 'a-zA-Z');
		
		# Define the regexp - as defined by Tony Finch in Message-ID: <cEj*NC0dr@news.chiark.greenend.org.uk> to ucam.comp.misc on 060412
		# NB: ^([a-z]{2,5})([1-9])([0-9]{0,4})$ doesn't deal with the few people with simply four letter CRSIDs
		$regexp = '^[a-z][a-z0-9]{1,7}$';
		
		# Return the result as a boolean
		return (ereg ($regexp, $crsid));
	}
	
	
	# Function to get user details
	function getLookupData ($crsids, $dumpData = false)
	{
		# Ensure the LDAP functionality exists in PHP
		if (!function_exists ('ldap_connect')) {
			return NULL;
		}
		
		# Connect to the lookup server
		if (!$ds = ldap_connect ('ldap.lookup.cam.ac.uk')) {
			return NULL;
		}
		
		# Bind the connection
		$r = ldap_bind ($ds);    // this is an "anonymous" bind, typically read-only access
		
		# Define the search string, imploding an array if in array format
		$searchString = (!is_array ($crsids) ? "uid={$crsids}" : '(|(uid=' . implode (')(uid=', $crsids) . '))');
		
		# Obtain the data
		$sr = ldap_search ($ds, 'ou=people,o=University of Cambridge,dc=cam,dc=ac,dc=uk', $searchString);  
		$data = ldap_get_entries ($ds, $sr);
		
		# Close the connection
		ldap_close ($ds);
		
		# End by returning false if no info or if the number of results is greater than the number supplied
		if (!$data || !$data['count'] || ($data['count'] > count ($crsids))) {
			return false;
		}
		
		# Dump data to screen if requested
		if ($dumpData) {
			require_once ('application.php');
			application::dumpData ($data);
		}
		
		# Arrange the data
		foreach ($data as $index => $person) {
			
			# Skip the count index
			if ($index === 'count') {continue;}
			
			# Get the CRSID first
			$crsid = $person['uid'][0];
			
			# Arrange the data
			$people[$crsid] = array (
				'name' => (isSet ($person['displayname']) ? $person['displayname'][0] : (isSet ($person['cn']) ? $person['cn'][0] : false)),
				'email' => (isSet ($person['mail']) ? $person['mail'][0] : "{$crsid}@cam.ac.uk"),
				'department' => (isSet ($person['ou']) ? $person['ou'][0] : false),
				'college' => ((isSet ($person['ou']) && isSet ($person['ou'][1])) ? $person['ou'][1] : false),
				'title' => (isSet ($person['title']) ? $person['title'][0] : false),
				'website' => (isSet ($person['labeleduri']) ? $person['labeleduri'][0] : false),
			);
		}
		
		# Return the data, in the same format as supplied, i.e. string/array
		return (!is_array ($crsids) ? $people[$crsids] : $people);
	}
}

?>