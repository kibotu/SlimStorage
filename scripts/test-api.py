#!/usr/bin/env -S uv run --with requests
"""
API Test Script for Key/Value Store API
Tests all endpoints with comprehensive coverage

Usage:
    uv run --with requests test-api.py
    or simply: ./test-api.py (if executable)
"""

import requests
import json
import sys
import os
from typing import Dict, Any, Tuple
import base64
from urllib.parse import quote

# Configuration
API_KEY = os.environ.get('API_KEY', '6ec67212c257e37d3085988dc8feba0b935c6694a0ce4ba1bcba39faf113a7f4')
BASE_URL = os.environ.get('BASE_URL', 'https://yourdomain.com/api')

# ANSI color codes
class Colors:
    RED = '\033[0;31m'
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    BLUE = '\033[0;34m'
    NC = '\033[0m'  # No Color

# Test counters
tests_passed = 0
tests_failed = 0

def print_test(message: str):
    """Print test description"""
    print(f"{Colors.BLUE}[TEST]{Colors.NC} {message}")

def print_success(message: str):
    """Print success message"""
    global tests_passed
    print(f"{Colors.GREEN}[PASS]{Colors.NC} {message}")
    tests_passed += 1

def print_error(message: str):
    """Print error message"""
    global tests_failed
    print(f"{Colors.RED}[FAIL]{Colors.NC} {message}")
    tests_failed += 1

def print_info(message: str):
    """Print info message"""
    print(f"{Colors.YELLOW}[INFO]{Colors.NC} {message}")

def print_separator():
    """Print separator line"""
    print()
    print("━" * 70)
    print()

def api_request(method: str, endpoint: str, data: Dict[str, Any] = None, 
                api_key: str = API_KEY) -> Tuple[int, Dict[str, Any]]:
    """
    Make an API request
    
    Args:
        method: HTTP method (GET, POST, DELETE)
        endpoint: API endpoint path
        data: Request body data (now used for all operations including get/delete/exists)
        api_key: API key to use
        
    Returns:
        Tuple of (status_code, response_json)
    """
    url = f"{BASE_URL}{endpoint}"
    headers = {
        'X-API-Key': api_key,
        'Content-Type': 'application/json'
    }
    
    try:
        if method == 'GET':
            response = requests.get(url, headers=headers, timeout=10)
        elif method == 'POST':
            response = requests.post(url, headers=headers, json=data, timeout=10)
        elif method == 'DELETE':
            response = requests.delete(url, headers=headers, timeout=10)
        else:
            raise ValueError(f"Unsupported method: {method}")
        
        try:
            response_json = response.json()
        except json.JSONDecodeError:
            response_json = {'raw': response.text}
        
        return response.status_code, response_json
    except requests.exceptions.RequestException as e:
        print_error(f"Request failed: {e}")
        return 0, {'error': str(e)}

def check_response(status_code: int, response: Dict[str, Any], 
                   expected_status: int, test_name: str) -> bool:
    """
    Check if response matches expected status
    
    Args:
        status_code: Actual HTTP status code
        response: Response JSON
        expected_status: Expected HTTP status code
        test_name: Name of the test
        
    Returns:
        True if test passed, False otherwise
    """
    if status_code == expected_status:
        print_success(f"{test_name} (HTTP {status_code})")
        print(f"    Response: {json.dumps(response, indent=2)}")
        return True
    else:
        print_error(f"{test_name} (Expected HTTP {expected_status}, got {status_code})")
        print(f"    Response: {json.dumps(response, indent=2)}")
        return False

def check_connectivity():
    """Check if the API is accessible"""
    try:
        response = requests.get(f"{BASE_URL}/list", headers={'X-API-Key': API_KEY}, timeout=5)
        return True
    except requests.exceptions.ConnectionError:
        return False
    except requests.exceptions.Timeout:
        return False
    except Exception:
        return True  # Other errors mean we can connect

def run_tests():
    """Run all API tests"""
    global tests_passed, tests_failed
    
    # Header
    print()
    print("╔" + "═" * 68 + "╗")
    print("║" + " " * 15 + "API Endpoint Test Suite" + " " * 30 + "║")
    print("╚" + "═" * 68 + "╝")
    print()
    print_info(f"API Key: {API_KEY[:16]}...{API_KEY[-8:]}")
    print_info(f"Base URL: {BASE_URL}")
    
    # Check connectivity
    print()
    print_info("Checking API connectivity...")
    if not check_connectivity():
        print_error("Cannot connect to API server!")
        print_error("Please check:")
        print_error("  1. The API is deployed and running")
        print_error("  2. The BASE_URL is correct")
        print_error("  3. Network connectivity")
        return 1
    print_info("✓ API server is reachable")
    print_separator()
    
    # Test 1: Clear all keys first (cleanup)
    print_test("1. Clear all existing keys")
    status, response = api_request('DELETE', '/clear')
    check_response(status, response, 200, "Clear all keys")
    print_separator()
    
    # Test 2: Set a value (get UUID key back)
    print_test("2. Store a value (get UUID key back)")
    status, response = api_request('POST', '/set', {'value': 'test_value_1'})
    if not check_response(status, response, 200, "Store value"):
        print_error("Failed to store value, cannot continue tests")
        return 1
    test_key_1 = response.get('key')
    print_info(f"Generated key: {test_key_1}")
    print_separator()
    
    # Test 3: Get the value using the UUID key
    print_test("3. Get the value using UUID key")
    status, response = api_request('POST', '/get', {'key': test_key_1})
    check_response(status, response, 200, "Get value by UUID")
    print_separator()
    
    # Test 4: Check if key exists
    print_test("4. Check if key exists")
    status, response = api_request('POST', '/exists', {'key': test_key_1})
    check_response(status, response, 200, "Check exists")
    print_separator()
    
    # Test 5: Store another value
    print_test("5. Store another value")
    status, response = api_request('POST', '/set', {'value': 'test_value_2'})
    if not check_response(status, response, 200, "Store second value"):
        print_error("Failed to store second value")
        return 1
    test_key_2 = response.get('key')
    print_info(f"Generated key: {test_key_2}")
    print_separator()
    
    # Test 6: Verify second value
    print_test("6. Verify second value")
    status, response = api_request('POST', '/get', {'key': test_key_2})
    check_response(status, response, 200, "Get second value")
    print_separator()
    
    # Test 7: Store multiple values
    print_test("7. Store multiple values")
    status, response = api_request('POST', '/set', {'value': 'value_3'})
    check_response(status, response, 200, "Store value 3")
    test_key_3 = response.get('key')
    status, response = api_request('POST', '/set', {'value': 'value_4'})
    check_response(status, response, 200, "Store value 4")
    test_key_4 = response.get('key')
    status, response = api_request('POST', '/set', {'value': 'value_5'})
    check_response(status, response, 200, "Store value 5")
    test_key_5 = response.get('key')
    print_separator()
    
    # Test 8: List all keys
    print_test("8. List all keys")
    status, response = api_request('GET', '/list')
    check_response(status, response, 200, "List all keys")
    print_separator()
    
    # Test 9: Store a large value
    print_test("9. Store large value (32KB)")
    large_value = base64.b64encode(os.urandom(32 * 1024)).decode('utf-8')
    status, response = api_request('POST', '/set', {'value': large_value})
    if not check_response(status, response, 200, "Store large value"):
        print_error("Failed to store large value")
        return 1
    large_key = response.get('key')
    print_separator()
    
    # Test 10: Get large value
    print_test("10. Get large value")
    status, response = api_request('POST', '/get', {'key': large_key})
    check_response(status, response, 200, "Get large value")
    print_separator()
    
    # Test 11: Store JSON value
    print_test("11. Store JSON value")
    json_value = json.dumps({'user': 'john', 'age': 30, 'active': True})
    status, response = api_request('POST', '/set', {'value': json_value})
    if not check_response(status, response, 200, "Store JSON value"):
        print_error("Failed to store JSON value")
        return 1
    json_key = response.get('key')
    print_separator()
    
    # Test 12: Get JSON value
    print_test("12. Get JSON value")
    status, response = api_request('POST', '/get', {'key': json_key})
    check_response(status, response, 200, "Get JSON value")
    print_separator()
    
    # Test 12a: Update existing key with new value
    print_test("12a. Update existing key with new value")
    status, response = api_request('POST', '/set', {'key': test_key_1, 'value': 'updated_value_1'})
    check_response(status, response, 200, "Update existing key")
    print_separator()
    
    # Test 12b: Verify updated value
    print_test("12b. Verify updated value")
    status, response = api_request('POST', '/get', {'key': test_key_1})
    if check_response(status, response, 200, "Get updated value"):
        if response.get('value') == 'updated_value_1':
            print_info("✓ Value was successfully updated")
        else:
            print_error(f"✗ Value mismatch: expected 'updated_value_1', got '{response.get('value')}'")
    print_separator()
    
    # Test 12c: Update same key again
    print_test("12c. Update same key again")
    status, response = api_request('POST', '/set', {'key': test_key_1, 'value': 'updated_value_2'})
    check_response(status, response, 200, "Update key second time")
    print_separator()
    
    # Test 12d: Verify second update
    print_test("12d. Verify second update")
    status, response = api_request('POST', '/get', {'key': test_key_1})
    if check_response(status, response, 200, "Get second updated value"):
        if response.get('value') == 'updated_value_2':
            print_info("✓ Value was successfully updated again")
        else:
            print_error(f"✗ Value mismatch: expected 'updated_value_2', got '{response.get('value')}'")
    print_separator()
    
    # Test 13: Delete a key
    print_test("13. Delete a key")
    status, response = api_request('POST', '/delete', {'key': test_key_2})
    check_response(status, response, 200, "Delete key")
    print_separator()
    
    # Test 14: Verify deletion
    print_test("14. Verify key is deleted")
    status, response = api_request('POST', '/get', {'key': test_key_2})
    check_response(status, response, 404, "Get deleted key (should fail)")
    print_separator()
    
    # Test 15: Check non-existent key (invalid UUID)
    print_test("15. Check if non-existent key exists")
    fake_uuid = '00000000-0000-4000-8000-000000000000'
    status, response = api_request('POST', '/exists', {'key': fake_uuid})
    check_response(status, response, 200, "Check exists for non-existent key")
    print_separator()
    
    # Test 16: Get non-existent key
    print_test("16. Get non-existent key")
    status, response = api_request('POST', '/get', {'key': fake_uuid})
    check_response(status, response, 404, "Get non-existent key (should fail)")
    print_separator()
    
    # Test 17: Delete non-existent key
    print_test("17. Delete non-existent key")
    status, response = api_request('POST', '/delete', {'key': fake_uuid})
    check_response(status, response, 404, "Delete non-existent key (should fail)")
    print_separator()
    
    # Test 20: Invalid API key
    print_test("20. Test with invalid API key")
    status, response = api_request('GET', '/list', api_key='invalid_key_12345')
    check_response(status, response, 401, "Request with invalid API key (should fail)")
    print_separator()
    
    # Test 21: Missing API key
    print_test("21. Test with missing API key")
    try:
        response = requests.get(f"{BASE_URL}/list", timeout=10)
        if response.status_code == 401:
            print_success(f"Request without API key (HTTP {response.status_code})")
        else:
            print_error(f"Request without API key (Expected HTTP 401, got {response.status_code})")
    except requests.exceptions.RequestException as e:
        print_error(f"Request failed: {e}")
    print_separator()
    
    # Test 22: Invalid JSON (handled by requests library)
    print_test("22. Test with invalid JSON")
    try:
        response = requests.post(
            f"{BASE_URL}/set",
            headers={'X-API-Key': API_KEY, 'Content-Type': 'application/json'},
            data='{"value":}',
            timeout=10
        )
        check_response(response.status_code, {}, 400, "Set with invalid JSON (should fail)")
    except requests.exceptions.RequestException as e:
        print_error(f"Request failed: {e}")
    print_separator()
    
    # Test 23: Missing required fields
    print_test("23. Test with missing required fields")
    status, response = api_request('POST', '/set', {})
    check_response(status, response, 400, "Set without value field (should fail)")
    print_separator()
    
    # Test 24: Invalid key format for get
    print_test("24. Test with invalid key format")
    status, response = api_request('POST', '/get', {'key': 'invalid-key-format'})
    check_response(status, response, 400, "Get with invalid key format (should fail)")
    print_separator()
    
    # Test 25: Invalid endpoint
    print_test("25. Test invalid endpoint")
    status, response = api_request('GET', '/invalid_endpoint')
    check_response(status, response, 404, "Invalid endpoint (should fail)")
    print_separator()
    
    # Test 26: List keys after operations
    print_test("26. Final list of all keys")
    status, response = api_request('GET', '/list')
    check_response(status, response, 200, "List all keys (final)")
    print_separator()
    
    # Test 27: Clear all keys (cleanup)
    print_test("27. Clear all keys (cleanup)")
    status, response = api_request('DELETE', '/clear')
    check_response(status, response, 200, "Clear all keys (cleanup)")
    print_separator()
    
    # Test 28: Verify clear
    print_test("28. Verify all keys cleared")
    status, response = api_request('GET', '/list')
    check_response(status, response, 200, "List keys after clear (should be empty)")
    print_separator()
    
    # Summary
    print()
    print("╔" + "═" * 68 + "╗")
    print("║" + " " * 20 + "Test Summary" + " " * 36 + "║")
    print("╚" + "═" * 68 + "╝")
    print()
    print(f"{Colors.GREEN}Tests Passed: {tests_passed}{Colors.NC}")
    print(f"{Colors.RED}Tests Failed: {tests_failed}{Colors.NC}")
    print()
    
    if tests_failed == 0:
        print(f"{Colors.GREEN}✓ All tests passed!{Colors.NC}")
        return 0
    else:
        print(f"{Colors.RED}✗ Some tests failed!{Colors.NC}")
        return 1

if __name__ == '__main__':
    try:
        exit_code = run_tests()
        sys.exit(exit_code)
    except KeyboardInterrupt:
        print("\n\nTests interrupted by user")
        sys.exit(1)
    except Exception as e:
        print_error(f"Unexpected error: {e}")
        sys.exit(1)

