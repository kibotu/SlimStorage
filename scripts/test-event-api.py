#!/usr/bin/env python3
"""
Event API Test Suite
Tests all event data endpoints with comprehensive scenarios
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

class EventAPITester:
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
    
    def test_push_single_event(self):
        """Test pushing a single event"""
        print(f"\n{BOLD}Test: Push Single Event{RESET}")
        
        data = {
            "data": {
                "temperature": 23.5,
                "humidity": 65,
                "device_id": "device-001"
            }
        }
        
        status, response = self.make_request('event/push', 'POST', data)
        
        self.assert_status(status, 200, "Status code is 200")
        self.assert_field(response, 'status', "Response has 'status' field")
        self.assert_equals(response.get('status'), 'success', "Status is 'success'")
        self.assert_field(response, 'count', "Response has 'count' field")
        self.assert_equals(response.get('count'), 1, "Count is 1")
    
    def test_push_single_event_with_timestamp(self):
        """Test pushing a single event with custom timestamp"""
        print(f"\n{BOLD}Test: Push Single Event with Timestamp{RESET}")
        
        timestamp = (datetime.now() - timedelta(hours=1)).isoformat() + 'Z'
        data = {
            "data": {
                "temperature": 24.0,
                "humidity": 60
            },
            "timestamp": timestamp
        }
        
        status, response = self.make_request('event/push', 'POST', data)
        
        self.assert_status(status, 200, "Status code is 200")
        self.assert_equals(response.get('status'), 'success', "Status is 'success'")
    
    def test_push_batch_events(self):
        """Test pushing multiple events in batch"""
        print(f"\n{BOLD}Test: Push Batch Events{RESET}")
        
        now = datetime.now()
        events = []
        for i in range(10):
            timestamp = (now - timedelta(minutes=i)).isoformat() + 'Z'
            events.append({
                "data": {
                    "temperature": 23.0 + i * 0.1,
                    "humidity": 65 - i,
                    "reading": i
                },
                "timestamp": timestamp
            })
        
        data = {"events": events}
        
        status, response = self.make_request('event/push', 'POST', data)
        
        self.assert_status(status, 200, "Status code is 200")
        self.assert_equals(response.get('status'), 'success', "Status is 'success'")
        self.assert_equals(response.get('count'), 10, "Count is 10")
    
    def test_push_invalid_data(self):
        """Test pushing invalid data"""
        print(f"\n{BOLD}Test: Push Invalid Data{RESET}")
        
        # Missing 'data' field
        data = {"timestamp": datetime.now().isoformat() + 'Z'}
        status, response = self.make_request('event/push', 'POST', data)
        self.assert_status(status, 400, "Missing 'data' field returns 400")
        
        # Invalid timestamp
        data = {
            "data": {"temp": 23.5},
            "timestamp": "invalid-timestamp"
        }
        status, response = self.make_request('event/push', 'POST', data)
        self.assert_status(status, 400, "Invalid timestamp returns 400")
    
    def test_query_all_events(self):
        """Test querying all events"""
        print(f"\n{BOLD}Test: Query All Events{RESET}")
        
        data = {"limit": 100}
        status, response = self.make_request('event/query', 'POST', data)
        
        self.assert_status(status, 200, "Status code is 200")
        self.assert_field(response, 'events', "Response has 'events' field")
        self.assert_field(response, 'total', "Response has 'total' field")
        self.assert_field(response, 'count', "Response has 'count' field")
        
        if 'total' in response:
            print(f"    {BLUE}ℹ{RESET} Total events in database: {response['total']}")
    
    def test_query_date_range(self):
        """Test querying events by date range"""
        print(f"\n{BOLD}Test: Query by Date Range{RESET}")
        
        end_date = datetime.now()
        start_date = end_date - timedelta(hours=2)
        
        data = {
            "start_date": start_date.isoformat() + 'Z',
            "end_date": end_date.isoformat() + 'Z',
            "limit": 50
        }
        
        status, response = self.make_request('event/query', 'POST', data)
        
        self.assert_status(status, 200, "Status code is 200")
        self.assert_field(response, 'events', "Response has 'events' field")
        
        if 'count' in response:
            print(f"    {BLUE}ℹ{RESET} Events in last 2 hours: {response['count']}")
    
    def test_query_with_pagination(self):
        """Test querying with pagination"""
        print(f"\n{BOLD}Test: Query with Pagination{RESET}")
        
        # First page
        data = {"limit": 5, "offset": 0}
        status, response = self.make_request('event/query', 'POST', data)
        
        self.assert_status(status, 200, "Status code is 200")
        self.assert_field(response, 'limit', "Response has 'limit' field")
        self.assert_field(response, 'offset', "Response has 'offset' field")
        self.assert_equals(response.get('limit'), 5, "Limit is 5")
        self.assert_equals(response.get('offset'), 0, "Offset is 0")
        
        # Second page
        data = {"limit": 5, "offset": 5}
        status, response = self.make_request('event/query', 'POST', data)
        
        self.assert_status(status, 200, "Second page status code is 200")
        self.assert_equals(response.get('offset'), 5, "Offset is 5")
    
    def test_query_ordering(self):
        """Test querying with different ordering"""
        print(f"\n{BOLD}Test: Query Ordering{RESET}")
        
        # Descending (newest first)
        data = {"limit": 5, "order": "desc"}
        status, response = self.make_request('event/query', 'POST', data)
        
        self.assert_status(status, 200, "DESC order status code is 200")
        self.assert_equals(response.get('order'), 'DESC', "Order is DESC")
        
        # Ascending (oldest first)
        data = {"limit": 5, "order": "asc"}
        status, response = self.make_request('event/query', 'POST', data)
        
        self.assert_status(status, 200, "ASC order status code is 200")
        self.assert_equals(response.get('order'), 'ASC', "Order is ASC")
    
    def test_query_invalid_params(self):
        """Test querying with invalid parameters"""
        print(f"\n{BOLD}Test: Query Invalid Parameters{RESET}")
        
        # Invalid limit
        data = {"limit": 20000}
        status, response = self.make_request('event/query', 'POST', data)
        self.assert_status(status, 400, "Limit too large returns 400")
        
        # Negative offset
        data = {"offset": -1}
        status, response = self.make_request('event/query', 'POST', data)
        self.assert_status(status, 400, "Negative offset returns 400")
    
    def test_get_stats(self):
        """Test getting event statistics"""
        print(f"\n{BOLD}Test: Get Statistics{RESET}")
        
        status, response = self.make_request('event/stats', 'GET')
        
        self.assert_status(status, 200, "Status code is 200")
        self.assert_field(response, 'total_events', "Response has 'total_events' field")
        self.assert_field(response, 'earliest_event', "Response has 'earliest_event' field")
        self.assert_field(response, 'latest_event', "Response has 'latest_event' field")
        self.assert_field(response, 'daily_stats_last_30_days', "Response has 'daily_stats_last_30_days' field")
        
        if 'total_events' in response:
            print(f"    {BLUE}ℹ{RESET} Total events: {response['total_events']}")
        if 'earliest_event' in response:
            print(f"    {BLUE}ℹ{RESET} Earliest event: {response['earliest_event']}")
        if 'latest_event' in response:
            print(f"    {BLUE}ℹ{RESET} Latest event: {response['latest_event']}")
    
    def test_missing_api_key(self):
        """Test request without API key"""
        print(f"\n{BOLD}Test: Missing API Key{RESET}")
        
        # Temporarily remove API key
        original_key = self.api_key
        self.api_key = ''
        
        status, response = self.make_request('event/stats', 'GET')
        self.assert_status(status, 401, "Missing API key returns 401")
        
        # Restore API key
        self.api_key = original_key
    
    def test_invalid_endpoint(self):
        """Test invalid endpoint"""
        print(f"\n{BOLD}Test: Invalid Endpoint{RESET}")
        
        status, response = self.make_request('event/invalid', 'GET')
        self.assert_status(status, 404, "Invalid endpoint returns 404")
    
    def test_clear_events(self):
        """Test clearing all events"""
        print(f"\n{BOLD}Test: Clear All Events{RESET}")
        
        # First, push some test events
        data = {
            "events": [
                {"data": {"test": "clear1"}},
                {"data": {"test": "clear2"}},
                {"data": {"test": "clear3"}}
            ]
        }
        status, response = self.make_request('event/push', 'POST', data)
        self.assert_status(status, 200, "Push test events returns 200")
        
        # Get stats before clear
        status, response = self.make_request('event/stats', 'GET')
        events_before = response.get('total_events', 0)
        print(f"    {BLUE}ℹ{RESET} Events before clear: {events_before}")
        
        # Clear all events
        status, response = self.make_request('event/clear', 'DELETE')
        self.assert_status(status, 200, "Clear events returns 200")
        self.assert_field(response, 'deleted_count', "Response has 'deleted_count' field")
        self.assert_equals(response.get('status'), 'success', "Status is 'success'")
        
        deleted_count = response.get('deleted_count', 0)
        print(f"    {BLUE}ℹ{RESET} Deleted {deleted_count} events")
        
        # Verify all events are cleared
        status, response = self.make_request('event/stats', 'GET')
        events_after = response.get('total_events', 0)
        self.assert_equals(events_after, 0, "All events cleared")
        print(f"    {BLUE}ℹ{RESET} Events after clear: {events_after}")
    
    def run_all_tests(self):
        """Run all tests"""
        print(f"\n{BOLD}{BLUE}{'='*60}{RESET}")
        print(f"{BOLD}{BLUE}Event API Test Suite{RESET}")
        print(f"{BOLD}{BLUE}{'='*60}{RESET}")
        print(f"Base URL: {self.base_url}")
        print(f"API Key: {self.api_key[:8]}...{self.api_key[-8:]}")
        
        # Run tests
        self.test_push_single_event()
        self.test_push_single_event_with_timestamp()
        self.test_push_batch_events()
        self.test_push_invalid_data()
        self.test_query_all_events()
        self.test_query_date_range()
        self.test_query_with_pagination()
        self.test_query_ordering()
        self.test_query_invalid_params()
        self.test_get_stats()
        self.test_missing_api_key()
        self.test_invalid_endpoint()
        self.test_clear_events()
        
        # Print summary
        total_tests = self.tests_passed + self.tests_failed
        print(f"\n{BOLD}{BLUE}{'='*60}{RESET}")
        print(f"{BOLD}Test Summary{RESET}")
        print(f"{BOLD}{BLUE}{'='*60}{RESET}")
        print(f"Total Tests: {total_tests}")
        print(f"{GREEN}Passed: {self.tests_passed}{RESET}")
        print(f"{RED}Failed: {self.tests_failed}{RESET}")
        
        if self.tests_failed == 0:
            print(f"\n{BOLD}{GREEN}🎉 All tests passed!{RESET}")
            return 0
        else:
            print(f"\n{BOLD}{RED}❌ Some tests failed{RESET}")
            return 1


def main():
    """Main entry point"""
    if len(sys.argv) != 3:
        print(f"Usage: {sys.argv[0]} <base_url> <api_key>")
        print(f"\nExample:")
        print(f"  {sys.argv[0]} https://services.yourdomain.com/api YOUR_API_KEY")
        sys.exit(1)
    
    base_url = sys.argv[1]
    api_key = sys.argv[2]
    
    tester = EventAPITester(base_url, api_key)
    exit_code = tester.run_all_tests()
    sys.exit(exit_code)


if __name__ == '__main__':
    main()

