use std::ffi::{c_char, CStr};

/// Returns the runtime version as an integer.
#[no_mangle]
pub extern "C" fn pico_rt_version() -> i32 {
    1
}

/// Concatenate two C strings into a new heap-allocated C string.
/// Caller receives ownership of the returned pointer.
#[no_mangle]
pub extern "C" fn pico_string_concat(a: *const c_char, b: *const c_char) -> *mut c_char {
    let a_str = unsafe { CStr::from_ptr(a) }.to_bytes();
    let b_str = unsafe { CStr::from_ptr(b) }.to_bytes();

    let mut result = Vec::with_capacity(a_str.len() + b_str.len() + 1);
    result.extend_from_slice(a_str);
    result.extend_from_slice(b_str);
    result.push(0); // null terminator

    let ptr = result.as_mut_ptr() as *mut c_char;
    std::mem::forget(result); // leak intentionally — batch process, freed on exit
    ptr
}

// ---------------------------------------------------------------------------
// Strings
// ---------------------------------------------------------------------------

#[no_mangle]
pub extern "C" fn pico_string_len(s: *const c_char) -> i32 {
    let s = unsafe { CStr::from_ptr(s) };
    s.to_bytes().len() as i32
}

#[no_mangle]
pub extern "C" fn pico_string_starts_with(haystack: *const c_char, prefix: *const c_char) -> i32 {
    let h = unsafe { CStr::from_ptr(haystack) }.to_bytes();
    let p = unsafe { CStr::from_ptr(prefix) }.to_bytes();
    h.starts_with(p) as i32
}

#[no_mangle]
pub extern "C" fn pico_string_contains(haystack: *const c_char, needle: *const c_char) -> i32 {
    let h = unsafe { CStr::from_ptr(haystack) }.to_bytes();
    let n = unsafe { CStr::from_ptr(needle) }.to_bytes();
    // windows() doesn't work for empty needle; match PHP behavior (always true)
    if n.is_empty() {
        return 1;
    }
    h.windows(n.len()).any(|w| w == n) as i32
}

/// Returns a heap-allocated substring. Negative $start counts from end.
/// If $length is negative, it's treated as 0 (returns empty string).
#[no_mangle]
pub extern "C" fn pico_string_substr(s: *const c_char, start: i32, length: i32) -> *mut c_char {
    let bytes = unsafe { CStr::from_ptr(s) }.to_bytes();
    let len = bytes.len() as i32;

    let actual_start = if start < 0 {
        (len + start).max(0) as usize
    } else {
        start.min(len) as usize
    };

    let actual_len = if length < 0 {
        0
    } else {
        length.min(len - actual_start as i32).max(0) as usize
    };

    let slice = &bytes[actual_start..actual_start + actual_len];
    let mut result = Vec::with_capacity(slice.len() + 1);
    result.extend_from_slice(slice);
    result.push(0);
    let ptr = result.as_mut_ptr() as *mut c_char;
    std::mem::forget(result);
    ptr
}

#[no_mangle]
pub extern "C" fn pico_string_trim(s: *const c_char) -> *mut c_char {
    let bytes = unsafe { CStr::from_ptr(s) }.to_bytes();
    let trimmed = match std::str::from_utf8(bytes) {
        Ok(s) => s.trim().as_bytes(),
        Err(_) => bytes, // non-UTF8: return as-is
    };
    let mut result = Vec::with_capacity(trimmed.len() + 1);
    result.extend_from_slice(trimmed);
    result.push(0);
    let ptr = result.as_mut_ptr() as *mut c_char;
    std::mem::forget(result);
    ptr
}

// ---------------------------------------------------------------------------
// Object allocation
// ---------------------------------------------------------------------------

/// Allocate `size` bytes for an object. The `type_id` is reserved for
/// future use (refcounting, GC metadata).
#[no_mangle]
pub extern "C" fn picohp_object_alloc(size: u64, _type_id: u32) -> *mut u8 {
    let layout = std::alloc::Layout::from_size_align(size as usize, 8).unwrap();
    unsafe { std::alloc::alloc_zeroed(layout) }
}

// ---------------------------------------------------------------------------
// Dynamic arrays
// ---------------------------------------------------------------------------

enum PicoValue {
    Int(i32),
    Float(f64),
    Bool(bool),
    Str(*const c_char),
    Ptr(*mut u8),
}

pub struct PicoArray {
    data: Vec<PicoValue>,
}

#[no_mangle]
pub extern "C" fn pico_array_new() -> *mut PicoArray {
    let arr = Box::new(PicoArray { data: Vec::new() });
    Box::into_raw(arr)
}

#[no_mangle]
pub extern "C" fn pico_array_len(arr: *const PicoArray) -> i32 {
    let arr = unsafe { &*arr };
    arr.data.len() as i32
}

// -- push -------------------------------------------------------------------

#[no_mangle]
pub extern "C" fn pico_array_push_int(arr: *mut PicoArray, val: i32) {
    let arr = unsafe { &mut *arr };
    arr.data.push(PicoValue::Int(val));
}

#[no_mangle]
pub extern "C" fn pico_array_push_float(arr: *mut PicoArray, val: f64) {
    let arr = unsafe { &mut *arr };
    arr.data.push(PicoValue::Float(val));
}

#[no_mangle]
pub extern "C" fn pico_array_push_bool(arr: *mut PicoArray, val: i32) {
    let arr = unsafe { &mut *arr };
    arr.data.push(PicoValue::Bool(val != 0));
}

#[no_mangle]
pub extern "C" fn pico_array_push_str(arr: *mut PicoArray, val: *const c_char) {
    let arr = unsafe { &mut *arr };
    arr.data.push(PicoValue::Str(val));
}

// -- get --------------------------------------------------------------------

#[no_mangle]
pub extern "C" fn pico_array_get_int(arr: *const PicoArray, index: i32) -> i32 {
    let arr = unsafe { &*arr };
    match &arr.data[index as usize] {
        PicoValue::Int(v) => *v,
        _ => panic!("pico_array_get_int: element is not an int"),
    }
}

#[no_mangle]
pub extern "C" fn pico_array_get_float(arr: *const PicoArray, index: i32) -> f64 {
    let arr = unsafe { &*arr };
    match &arr.data[index as usize] {
        PicoValue::Float(v) => *v,
        _ => panic!("pico_array_get_float: element is not a float"),
    }
}

#[no_mangle]
pub extern "C" fn pico_array_get_bool(arr: *const PicoArray, index: i32) -> i32 {
    let arr = unsafe { &*arr };
    match &arr.data[index as usize] {
        PicoValue::Bool(v) => *v as i32,
        _ => panic!("pico_array_get_bool: element is not a bool"),
    }
}

#[no_mangle]
pub extern "C" fn pico_array_get_str(arr: *const PicoArray, index: i32) -> *const c_char {
    let arr = unsafe { &*arr };
    match &arr.data[index as usize] {
        PicoValue::Str(v) => *v,
        _ => panic!("pico_array_get_str: element is not a string"),
    }
}

// -- set --------------------------------------------------------------------

#[no_mangle]
pub extern "C" fn pico_array_set_int(arr: *mut PicoArray, index: i32, val: i32) {
    let arr = unsafe { &mut *arr };
    arr.data[index as usize] = PicoValue::Int(val);
}

#[no_mangle]
pub extern "C" fn pico_array_set_float(arr: *mut PicoArray, index: i32, val: f64) {
    let arr = unsafe { &mut *arr };
    arr.data[index as usize] = PicoValue::Float(val);
}

#[no_mangle]
pub extern "C" fn pico_array_set_bool(arr: *mut PicoArray, index: i32, val: i32) {
    let arr = unsafe { &mut *arr };
    arr.data[index as usize] = PicoValue::Bool(val != 0);
}

#[no_mangle]
pub extern "C" fn pico_array_set_str(arr: *mut PicoArray, index: i32, val: *const c_char) {
    let arr = unsafe { &mut *arr };
    arr.data[index as usize] = PicoValue::Str(val);
}

// -- ptr (object pointers) --------------------------------------------------

#[no_mangle]
pub extern "C" fn pico_array_push_ptr(arr: *mut PicoArray, val: *mut u8) {
    let arr = unsafe { &mut *arr };
    arr.data.push(PicoValue::Ptr(val));
}

#[no_mangle]
pub extern "C" fn pico_array_get_ptr(arr: *const PicoArray, index: i32) -> *mut u8 {
    let arr = unsafe { &*arr };
    match &arr.data[index as usize] {
        PicoValue::Ptr(v) => *v,
        _ => panic!("pico_array_get_ptr: element is not a pointer"),
    }
}

#[no_mangle]
pub extern "C" fn pico_array_set_ptr(arr: *mut PicoArray, index: i32, val: *mut u8) {
    let arr = unsafe { &mut *arr };
    arr.data[index as usize] = PicoValue::Ptr(val);
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::CString;

    #[test]
    fn test_string_len() {
        let s = CString::new("hello").unwrap();
        assert_eq!(pico_string_len(s.as_ptr()), 5);
        let empty = CString::new("").unwrap();
        assert_eq!(pico_string_len(empty.as_ptr()), 0);
    }

    #[test]
    fn test_string_starts_with() {
        let h = CString::new("hello world").unwrap();
        let p1 = CString::new("hello").unwrap();
        let p2 = CString::new("world").unwrap();
        let p3 = CString::new("").unwrap();
        assert_eq!(pico_string_starts_with(h.as_ptr(), p1.as_ptr()), 1);
        assert_eq!(pico_string_starts_with(h.as_ptr(), p2.as_ptr()), 0);
        assert_eq!(pico_string_starts_with(h.as_ptr(), p3.as_ptr()), 1);
    }

    #[test]
    fn test_string_contains() {
        let h = CString::new("hello world").unwrap();
        let n1 = CString::new("world").unwrap();
        let n2 = CString::new("xyz").unwrap();
        let n3 = CString::new("").unwrap();
        assert_eq!(pico_string_contains(h.as_ptr(), n1.as_ptr()), 1);
        assert_eq!(pico_string_contains(h.as_ptr(), n2.as_ptr()), 0);
        assert_eq!(pico_string_contains(h.as_ptr(), n3.as_ptr()), 1);
    }

    #[test]
    fn test_string_substr() {
        let s = CString::new("hello world").unwrap();
        let r1 = pico_string_substr(s.as_ptr(), 0, 5);
        assert_eq!(unsafe { CStr::from_ptr(r1) }.to_str().unwrap(), "hello");
        let r2 = pico_string_substr(s.as_ptr(), 6, 5);
        assert_eq!(unsafe { CStr::from_ptr(r2) }.to_str().unwrap(), "world");
        let r3 = pico_string_substr(s.as_ptr(), -5, 5);
        assert_eq!(unsafe { CStr::from_ptr(r3) }.to_str().unwrap(), "world");
    }

    #[test]
    fn test_string_trim() {
        let s = CString::new("  hello  ").unwrap();
        let r = pico_string_trim(s.as_ptr());
        assert_eq!(unsafe { CStr::from_ptr(r) }.to_str().unwrap(), "hello");
        let s2 = CString::new("nospace").unwrap();
        let r2 = pico_string_trim(s2.as_ptr());
        assert_eq!(unsafe { CStr::from_ptr(r2) }.to_str().unwrap(), "nospace");
    }

    #[test]
    fn test_object_alloc() {
        let ptr = picohp_object_alloc(16, 0);
        assert!(!ptr.is_null());
        // Verify zeroed
        unsafe {
            assert_eq!(*ptr, 0);
            assert_eq!(*ptr.add(15), 0);
        }
    }

    #[test]
    fn test_version() {
        assert_eq!(pico_rt_version(), 1);
    }

    #[test]
    fn test_array_push_get_ptr() {
        let arr = pico_array_new();
        let obj1 = picohp_object_alloc(16, 0);
        let obj2 = picohp_object_alloc(16, 1);
        pico_array_push_ptr(arr, obj1);
        pico_array_push_ptr(arr, obj2);
        assert_eq!(pico_array_len(arr), 2);
        assert_eq!(pico_array_get_ptr(arr, 0), obj1);
        assert_eq!(pico_array_get_ptr(arr, 1), obj2);
    }

    #[test]
    fn test_string_concat() {
        let a = CString::new("Hello").unwrap();
        let b = CString::new(" World").unwrap();
        let result = pico_string_concat(a.as_ptr(), b.as_ptr());
        let result_str = unsafe { CStr::from_ptr(result) };
        assert_eq!(result_str.to_str().unwrap(), "Hello World");
    }

    #[test]
    fn test_array_new_and_len() {
        let arr = pico_array_new();
        assert_eq!(pico_array_len(arr), 0);
    }

    #[test]
    fn test_array_push_get_int() {
        let arr = pico_array_new();
        pico_array_push_int(arr, 42);
        pico_array_push_int(arr, -7);
        assert_eq!(pico_array_len(arr), 2);
        assert_eq!(pico_array_get_int(arr, 0), 42);
        assert_eq!(pico_array_get_int(arr, 1), -7);
    }

    #[test]
    fn test_array_push_get_float() {
        let arr = pico_array_new();
        pico_array_push_float(arr, 3.14);
        pico_array_push_float(arr, -2.5);
        assert_eq!(pico_array_len(arr), 2);
        assert!((pico_array_get_float(arr, 0) - 3.14).abs() < f64::EPSILON);
        assert!((pico_array_get_float(arr, 1) - (-2.5)).abs() < f64::EPSILON);
    }

    #[test]
    fn test_array_push_get_str() {
        let arr = pico_array_new();
        let s1 = CString::new("hello").unwrap();
        let s2 = CString::new("world").unwrap();
        pico_array_push_str(arr, s1.as_ptr());
        pico_array_push_str(arr, s2.as_ptr());
        assert_eq!(pico_array_len(arr), 2);
        let r1 = unsafe { CStr::from_ptr(pico_array_get_str(arr, 0)) };
        let r2 = unsafe { CStr::from_ptr(pico_array_get_str(arr, 1)) };
        assert_eq!(r1.to_str().unwrap(), "hello");
        assert_eq!(r2.to_str().unwrap(), "world");
    }

    #[test]
    fn test_array_push_get_bool() {
        let arr = pico_array_new();
        pico_array_push_bool(arr, 1);
        pico_array_push_bool(arr, 0);
        assert_eq!(pico_array_len(arr), 2);
        assert_eq!(pico_array_get_bool(arr, 0), 1);
        assert_eq!(pico_array_get_bool(arr, 1), 0);
    }

    #[test]
    fn test_array_set_bool() {
        let arr = pico_array_new();
        pico_array_push_bool(arr, 0);
        pico_array_set_bool(arr, 0, 1);
        assert_eq!(pico_array_get_bool(arr, 0), 1);
    }

    #[test]
    fn test_array_set_int() {
        let arr = pico_array_new();
        pico_array_push_int(arr, 1);
        pico_array_push_int(arr, 2);
        pico_array_set_int(arr, 0, 99);
        assert_eq!(pico_array_get_int(arr, 0), 99);
        assert_eq!(pico_array_get_int(arr, 1), 2);
    }

    #[test]
    fn test_array_set_float() {
        let arr = pico_array_new();
        pico_array_push_float(arr, 1.0);
        pico_array_set_float(arr, 0, 9.9);
        assert!((pico_array_get_float(arr, 0) - 9.9).abs() < f64::EPSILON);
    }

    #[test]
    fn test_array_set_str() {
        let arr = pico_array_new();
        let s1 = CString::new("old").unwrap();
        let s2 = CString::new("new").unwrap();
        pico_array_push_str(arr, s1.as_ptr());
        pico_array_set_str(arr, 0, s2.as_ptr());
        let r = unsafe { CStr::from_ptr(pico_array_get_str(arr, 0)) };
        assert_eq!(r.to_str().unwrap(), "new");
    }
}
