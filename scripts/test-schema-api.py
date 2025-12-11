#!/usr/bin/env python3
"""
Schema API Test Suite
Tests all schema optimization endpoints with comprehensive scenarios
"""

import json
import sys
import time
from datetime import datetime, timedelta
from typing import Dict, Any, Optional
import urllib.request
import urllib.error
import urllib.parse

# ANSI color codes
GREEN = '\033[92m'
RED = '\033[91m'
YELLOW = '\033[93m'
BLUE = '\033[94m'
RESET = '\033[0m'
BOLD = '\033[1m'

class SchemaAPITester:
    def __init__(self, base_url: str, api_key: str):
        self.base_url = base_url.rstrip('/')
        self.api_key = api_key
        self.tests_passed = 0
        self.tests_failed = 0
        
    def make_request(
        self,
        endpoint: str,
        method: str = 'GET',
        data: Optional[Dict[str, Any]] = None
    ) -> tuple[int, Dict[str, Any]]:
        """Make HTTP request to API"""
        url = f"{self.base_url}/{endpoint.lstrip('/')}"
        
        headers = {
            'X-API-Key': self.api_key,
            'Content-Type': 'application/json'
        }
        
        request_data = None
        if data is not None:
            request_data = json.dumps(data).encode('utf-8')
        
        req = urllib.request.Request(
            url,
            data=request_data,
            headers=headers,
            method=method
        )
        
        try:
            with urllib.request.urlopen(req) as response:
                status_code = response.status
                response_data = json.loads(response.read().decode('utf-8'))
                return status_code, response_data
        except urllib.error.HTTPError as e:
            status_code = e.code
            try:
                response_data = json.loads(e.read().decode('utf-8'))
            except:
                response_data = {'error': str(e)}
            return status_code, response_data
        except Exception as e:
            return 0, {'error': str(e)}
    
    def assert_status(self, actual: int, expected: int, test_name: str) -> bool:
        """Assert HTTP status code"""
        if actual == expected:
            print(f"  {GREEN}✓{RESET} {test_name}")
            self.tests_passed += 1
            return True
        else:
            print(f"  {RED}✗{RESET} {test_name}")
            print(f"    Expected status {expected}, got {actual}")
            self.tests_failed += 1
            return False
    
    def assert_field(self, data: Dict, field: str, test_name: str) -> bool:
        """Assert field exists in response"""
        if field in data:
            print(f"  {GREEN}✓{RESET} {test_name}")
            self.tests_passed += 1
            return True
        else:
            print(f"  {RED}✗{RESET} {test_name}")
            print(f"    Field '{field}' not found in response")
            self.tests_failed += 1
            return False
    
    def assert_equals(self, actual: Any, expected: Any, test_name: str) -> bool:
        """Assert values are equal"""
        if actual == expected:
            print(f"  {GREEN}✓{RESET} {test_name}")
            self.tests_passed += 1
            return True
        else:
            print(f"  {RED}✗{RESET} {test_name}")
            print(f"    Expected {expected}, got {actual}")
            self.tests_failed += 1
            return False

    # =========================================================================
    # Test Cases
    # =========================================================================

    def test_get_schema_no_schema(self):
        """Test getting schema when none is defined"""
        print(f"\n{BOLD}📋 GET /schema - No Schema Defined{RESET}")
        
        status, response = self.make_request('schema', 'GET')
        
        self.assert_status(status, 200, "GET schema returns 200")
        self.assert_field(response, 'status', "Response has 'status' field")
        
        if response.get('schema') is None:
            print(f"  {GREEN}✓{RESET} Schema is null (none defined)")
            self.tests_passed += 1
        else:
            print(f"  {BLUE}ℹ{RESET} Schema already exists - will delete first")

    def test_delete_schema_cleanup(self):
        """Delete any existing schema for cleanup"""
        print(f"\n{BOLD}🗑️  DELETE /schema - Cleanup{RESET}")
        
        status, response = self.make_request('schema', 'DELETE')
        
        # Either 200 (deleted) or 404 (didn't exist) is acceptable
        if status in [200, 404]:
            print(f"  {GREEN}✓{RESET} Cleanup successful (status {status})")
            self.tests_passed += 1
        else:
            print(f"  {RED}✗{RESET} Unexpected status {status}")
            self.tests_failed += 1

    def test_create_schema_missing_fields(self):
        """Test schema creation with missing fields"""
        print(f"\n{BOLD}❌ POST /schema - Missing Fields{RESET}")
        
        # Empty body
        status, response = self.make_request('schema', 'POST', {})
        self.assert_status(status, 400, "Empty body returns 400")
        
        # Missing aggregations (should use default)
        status, response = self.make_request('schema', 'POST', {
            "fields": []
        })
        self.assert_status(status, 400, "Empty fields array returns 400")

    def test_create_schema_invalid_type(self):
        """Test schema creation with invalid field type"""
        print(f"\n{BOLD}❌ POST /schema - Invalid Field Type{RESET}")
        
        status, response = self.make_request('schema', 'POST', {
            "fields": [
                {"name": "test", "type": "invalid_type"}
            ]
        })
        self.assert_status(status, 400, "Invalid type returns 400")
        
        if 'message' in response:
            print(f"    {BLUE}ℹ{RESET} Error: {response['message']}")

    def test_create_schema_success(self):
        """Test successful schema creation"""
        print(f"\n{BOLD}✅ POST /schema - Create Schema{RESET}")
        
        schema_data = {
            "fields": [
                {"name": "cpm", "type": "integer"},
                {"name": "usvh", "type": "float"},
                {"name": "temperature", "type": "double"}
            ],
            "aggregations": ["hourly", "daily"]
        }
        
        status, response = self.make_request('schema', 'POST', schema_data)
        
        self.assert_status(status, 201, "Schema creation returns 201")
        self.assert_field(response, 'status', "Response has 'status' field")
        self.assert_equals(response.get('status'), 'success', "Status is 'success'")
        self.assert_field(response, 'schema', "Response has 'schema' field")
        
        if 'schema' in response:
            schema = response['schema']
            self.assert_field(schema, 'fields', "Schema has 'fields'")
            self.assert_field(schema, 'aggregations', "Schema has 'aggregations'")
            print(f"    {BLUE}ℹ{RESET} Created {len(schema.get('fields', []))} fields")

    def test_get_schema_after_create(self):
        """Test getting schema after creation"""
        print(f"\n{BOLD}📋 GET /schema - After Creation{RESET}")
        
        status, response = self.make_request('schema', 'GET')
        
        self.assert_status(status, 200, "GET schema returns 200")
        self.assert_field(response, 'schema', "Response has 'schema' field")
        
        if response.get('schema'):
            schema = response['schema']
            self.assert_field(schema, 'fields', "Schema has 'fields'")
            self.assert_field(schema, 'aggregations', "Schema has 'aggregations'")
            
            # Check aggregation status
            aggs = schema.get('aggregations', {})
            if 'hourly' in aggs:
                print(f"    {BLUE}ℹ{RESET} Hourly: {aggs['hourly'].get('status')} ({aggs['hourly'].get('row_count', 0)} rows)")
            if 'daily' in aggs:
                print(f"    {BLUE}ℹ{RESET} Daily: {aggs['daily'].get('status')} ({aggs['daily'].get('row_count', 0)} rows)")

    def test_create_schema_duplicate(self):
        """Test creating schema when one already exists"""
        print(f"\n{BOLD}❌ POST /schema - Duplicate Schema{RESET}")
        
        status, response = self.make_request('schema', 'POST', {
            "fields": [{"name": "test", "type": "integer"}]
        })
        
        self.assert_status(status, 409, "Duplicate schema returns 409 Conflict")
        
        if 'message' in response:
            print(f"    {BLUE}ℹ{RESET} Error: {response['message']}")

    def test_push_event_with_schema(self):
        """Test pushing events with schema defined"""
        print(f"\n{BOLD}📤 POST /event/push - With Schema{RESET}")
        
        # Push some test events
        events = []
        for i in range(5):
            events.append({
                "data": {
                    "cpm": 30 + i,
                    "usvh": 0.2 + (i * 0.01),
                    "temperature": 20.0 + i
                }
            })
        
        status, response = self.make_request('event/push', 'POST', {"events": events})
        
        self.assert_status(status, 200, "Push events returns 200")
        self.assert_equals(response.get('count'), 5, "5 events pushed")
        
        print(f"    {BLUE}ℹ{RESET} Pushed {response.get('count', 0)} events with schema fields")

    def test_aggregate_hourly(self):
        """Test hourly aggregation query"""
        print(f"\n{BOLD}📊 POST /event/aggregate - Hourly{RESET}")
        
        status, response = self.make_request('event/aggregate', 'POST', {
            "granularity": "hourly"
        })
        
        self.assert_status(status, 200, "Aggregate query returns 200")
        self.assert_field(response, 'granularity', "Response has 'granularity' field")
        self.assert_equals(response.get('granularity'), 'hourly', "Granularity is 'hourly'")
        self.assert_field(response, 'data', "Response has 'data' field")
        self.assert_field(response, 'fields', "Response has 'fields' field")
        
        if response.get('data'):
            print(f"    {BLUE}ℹ{RESET} Got {len(response['data'])} hourly data points")
            if len(response['data']) > 0:
                first = response['data'][0]
                print(f"    {BLUE}ℹ{RESET} Sample: period={first.get('period')}, count={first.get('event_count')}")

    def test_aggregate_daily(self):
        """Test daily aggregation query"""
        print(f"\n{BOLD}📊 POST /event/aggregate - Daily{RESET}")
        
        status, response = self.make_request('event/aggregate', 'POST', {
            "granularity": "daily"
        })
        
        self.assert_status(status, 200, "Aggregate query returns 200")
        self.assert_equals(response.get('granularity'), 'daily', "Granularity is 'daily'")
        self.assert_field(response, 'data', "Response has 'data' field")
        
        if response.get('data'):
            print(f"    {BLUE}ℹ{RESET} Got {len(response['data'])} daily data points")

    def test_aggregate_with_date_filter(self):
        """Test aggregation with date range filter"""
        print(f"\n{BOLD}📊 POST /event/aggregate - With Date Filter{RESET}")
        
        today = datetime.now().strftime('%Y-%m-%d')
        yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
        
        status, response = self.make_request('event/aggregate', 'POST', {
            "granularity": "daily",
            "start_date": yesterday,
            "end_date": today
        })
        
        self.assert_status(status, 200, "Filtered aggregate returns 200")
        self.assert_field(response, 'data', "Response has 'data' field")
        
        print(f"    {BLUE}ℹ{RESET} Date range: {yesterday} to {today}")
        print(f"    {BLUE}ℹ{RESET} Got {len(response.get('data', []))} data points")

    def test_aggregate_invalid_granularity(self):
        """Test aggregation with invalid granularity"""
        print(f"\n{BOLD}❌ POST /event/aggregate - Invalid Granularity{RESET}")
        
        status, response = self.make_request('event/aggregate', 'POST', {
            "granularity": "weekly"
        })
        
        self.assert_status(status, 400, "Invalid granularity returns 400")

    def test_rebuild_schema(self):
        """Test rebuilding aggregations"""
        print(f"\n{BOLD}🔄 POST /schema/rebuild - Rebuild Aggregations{RESET}")
        
        status, response = self.make_request('schema/rebuild', 'POST')
        
        self.assert_status(status, 200, "Rebuild returns 200")
        self.assert_field(response, 'rebuilt', "Response has 'rebuilt' field")
        
        if 'rebuilt' in response:
            rebuilt = response['rebuilt']
            print(f"    {BLUE}ℹ{RESET} Rebuilt hourly: {rebuilt.get('hourly', 0)} rows")
            print(f"    {BLUE}ℹ{RESET} Rebuilt daily: {rebuilt.get('daily', 0)} rows")

    def test_delete_schema(self):
        """Test deleting schema"""
        print(f"\n{BOLD}🗑️  DELETE /schema - Delete Schema{RESET}")
        
        status, response = self.make_request('schema', 'DELETE')
        
        self.assert_status(status, 200, "Delete schema returns 200")
        self.assert_field(response, 'removed', "Response has 'removed' field")
        
        if 'removed' in response:
            removed = response['removed']
            print(f"    {BLUE}ℹ{RESET} Removed {removed.get('fields', 0)} fields")
            print(f"    {BLUE}ℹ{RESET} Removed aggregations: {removed.get('aggregations', [])}")

    def test_aggregate_no_schema(self):
        """Test aggregation when no schema exists"""
        print(f"\n{BOLD}❌ POST /event/aggregate - No Schema{RESET}")
        
        status, response = self.make_request('event/aggregate', 'POST', {
            "granularity": "hourly"
        })
        
        self.assert_status(status, 404, "Aggregate without schema returns 404")
        
        if 'message' in response:
            print(f"    {BLUE}ℹ{RESET} Error: {response['message']}")

    def run_all_tests(self):
        """Run all test cases in order"""
        print(f"\n{BOLD}{BLUE}{'='*50}{RESET}")
        print(f"{BOLD}{BLUE}  Schema API Test Suite{RESET}")
        print(f"{BOLD}{BLUE}{'='*50}{RESET}")
        print(f"\n  API URL: {self.base_url}")
        print(f"  API Key: {self.api_key[:16]}...{self.api_key[-8:]}")
        
        # Cleanup first
        self.test_delete_schema_cleanup()
        
        # Test no schema state
        self.test_get_schema_no_schema()
        
        # Test validation
        self.test_create_schema_missing_fields()
        self.test_create_schema_invalid_type()
        
        # Test schema creation
        self.test_create_schema_success()
        self.test_get_schema_after_create()
        self.test_create_schema_duplicate()
        
        # Test with events
        self.test_push_event_with_schema()
        
        # Test aggregation
        self.test_aggregate_hourly()
        self.test_aggregate_daily()
        self.test_aggregate_with_date_filter()
        self.test_aggregate_invalid_granularity()
        
        # Test rebuild
        self.test_rebuild_schema()
        
        # Test delete
        self.test_delete_schema()
        self.test_aggregate_no_schema()
        
        # Print summary
        print(f"\n{BOLD}{BLUE}{'='*50}{RESET}")
        print(f"{BOLD}  Test Summary{RESET}")
        print(f"{BOLD}{BLUE}{'='*50}{RESET}")
        print(f"\n  Total Tests: {self.tests_passed + self.tests_failed}")
        print(f"  {GREEN}Passed: {self.tests_passed}{RESET}")
        print(f"  {RED}Failed: {self.tests_failed}{RESET}")
        
        if self.tests_failed == 0:
            print(f"\n{BOLD}{GREEN}🎉 All tests passed!{RESET}\n")
        else:
            print(f"\n{BOLD}{RED}❌ Some tests failed{RESET}\n")
        
        return self.tests_failed == 0


def main():
    # Default values
    api_url = "https://yourdomain.com/api"
    api_key = None
    
    # Parse command line arguments
    if len(sys.argv) >= 3:
        api_url = sys.argv[1]
        api_key = sys.argv[2]
    elif len(sys.argv) == 2:
        # Single argument - could be URL or API key
        arg = sys.argv[1]
        if arg.startswith('http'):
            api_url = arg
        else:
            api_key = arg
    
    # Check for API key in environment
    import os
    if not api_key:
        api_key = os.environ.get('API_KEY')
    
    if not api_key:
        print(f"{RED}Error: API key required{RESET}")
        print(f"\nUsage: {sys.argv[0]} [API_URL] API_KEY")
        print(f"   or: API_KEY=xxx {sys.argv[0]}")
        sys.exit(1)
    
    tester = SchemaAPITester(api_url, api_key)
    success = tester.run_all_tests()
    
    sys.exit(0 if success else 1)


if __name__ == '__main__':
    main()

