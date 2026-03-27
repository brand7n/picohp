use std::ffi::{c_char, c_int, CStr, CString};
use regex::Regex;

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
pub extern "C" fn pico_int_to_string(val: i32) -> *mut c_char {
    let s = val.to_string();
    let c_str = CString::new(s).unwrap();
    c_str.into_raw()
}

extern "C" {
    fn snprintf(s: *mut c_char, n: usize, format: *const c_char, ...) -> c_int;
}

/// Convert f64 to string matching PHP's `(string)` cast (%.14G format).
#[no_mangle]
pub extern "C" fn pico_float_to_string(val: f64) -> *mut c_char {
    let mut buf = [0u8; 64];
    let fmt = b"%.14G\0";
    let len = unsafe {
        snprintf(
            buf.as_mut_ptr() as *mut c_char,
            buf.len(),
            fmt.as_ptr() as *const c_char,
            val,
        )
    };
    let s = &buf[..len as usize];
    let c_str = CString::new(s).unwrap();
    c_str.into_raw()
}

/// Convert f64 to its IEEE 754 hex representation (e.g. "0x400921FB54442D18").
#[no_mangle]
pub extern "C" fn pico_float_to_hex(val: f64) -> *mut c_char {
    let bits = val.to_bits();
    let s = format!("0x{:016X}", bits);
    let c_str = CString::new(s).unwrap();
    c_str.into_raw()
}

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

/// PHP-compatible string strict equality (===): compare string bytes.
#[no_mangle]
pub extern "C" fn pico_string_eq(a: *const c_char, b: *const c_char) -> i32 {
    let a = unsafe { CStr::from_ptr(a) }.to_bytes();
    let b = unsafe { CStr::from_ptr(b) }.to_bytes();
    (a == b) as i32
}

/// PHP-compatible string strict inequality (!==): compare string bytes.
#[no_mangle]
pub extern "C" fn pico_string_ne(a: *const c_char, b: *const c_char) -> i32 {
    let a = unsafe { CStr::from_ptr(a) }.to_bytes();
    let b = unsafe { CStr::from_ptr(b) }.to_bytes();
    (a != b) as i32
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
pub extern "C" fn pico_string_repeat(s: *const c_char, times: i32) -> *mut c_char {
    let bytes = unsafe { CStr::from_ptr(s) }.to_bytes();
    let times = times.max(0) as usize;
    let mut result = Vec::with_capacity(bytes.len() * times + 1);
    for _ in 0..times {
        result.extend_from_slice(bytes);
    }
    result.push(0);
    let ptr = result.as_mut_ptr() as *mut c_char;
    std::mem::forget(result);
    ptr
}

#[no_mangle]
pub extern "C" fn pico_string_replace(
    search: *const c_char,
    replace: *const c_char,
    subject: *const c_char,
) -> *mut c_char {
    let search_bytes = unsafe { CStr::from_ptr(search) }.to_bytes();
    let replace_bytes = unsafe { CStr::from_ptr(replace) }.to_bytes();
    let subject_bytes = unsafe { CStr::from_ptr(subject) }.to_bytes();

    if search_bytes.is_empty() {
        // PHP returns subject unchanged for empty search
        let mut result = Vec::with_capacity(subject_bytes.len() + 1);
        result.extend_from_slice(subject_bytes);
        result.push(0);
        let ptr = result.as_mut_ptr() as *mut c_char;
        std::mem::forget(result);
        return ptr;
    }

    let mut result = Vec::new();
    let mut i = 0;
    while i < subject_bytes.len() {
        if i + search_bytes.len() <= subject_bytes.len()
            && &subject_bytes[i..i + search_bytes.len()] == search_bytes
        {
            result.extend_from_slice(replace_bytes);
            i += search_bytes.len();
        } else {
            result.push(subject_bytes[i]);
            i += 1;
        }
    }
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
// Exception handling (setjmp/longjmp based)
// ---------------------------------------------------------------------------

struct ExceptionState {
    /// Pointer to the current in-flight exception object (null if none).
    exception_ptr: *mut u8,
    /// The type_id of the current in-flight exception (for catch-clause matching).
    exception_type_id: u32,
    /// Stack of jmp_buf pointers — each try block pushes one.
    handler_stack: Vec<*mut u8>,
}

static mut EXCEPTION_STATE: ExceptionState = ExceptionState {
    exception_ptr: std::ptr::null_mut(),
    exception_type_id: 0,
    handler_stack: Vec::new(),
};

extern "C" {
    fn longjmp(env: *mut u8, val: i32) -> !;
}

/// Push a jmp_buf pointer onto the exception handler stack.
/// Called right before setjmp() in the generated IR.
#[no_mangle]
pub extern "C" fn picohp_eh_push(jmp_buf_ptr: *mut u8) {
    unsafe {
        EXCEPTION_STATE.handler_stack.push(jmp_buf_ptr);
    }
}

/// Pop the top jmp_buf from the handler stack (normal exit from try block).
#[no_mangle]
pub extern "C" fn picohp_eh_pop() {
    unsafe {
        EXCEPTION_STATE.handler_stack.pop();
    }
}

/// Throw an exception: store the object pointer and type_id, then longjmp.
/// If no handler is on the stack, prints an error and aborts.
#[no_mangle]
pub extern "C" fn picohp_throw(exception_ptr: *mut u8, type_id: u32) {
    unsafe {
        EXCEPTION_STATE.exception_ptr = exception_ptr;
        EXCEPTION_STATE.exception_type_id = type_id;
        if let Some(&jmp_buf_ptr) = EXCEPTION_STATE.handler_stack.last() {
            longjmp(jmp_buf_ptr, 1);
        } else {
            eprintln!("Fatal error: Uncaught exception (type_id={})", type_id);
            std::process::exit(1);
        }
    }
}

/// Get the current in-flight exception object pointer.
#[no_mangle]
pub extern "C" fn picohp_eh_get_exception() -> *mut u8 {
    unsafe { EXCEPTION_STATE.exception_ptr }
}

/// Get the type_id of the current in-flight exception.
#[no_mangle]
pub extern "C" fn picohp_eh_get_type_id() -> u32 {
    unsafe { EXCEPTION_STATE.exception_type_id }
}

/// Check if exception type_id matches a given type_id or any of its ancestors.
/// The ancestor_ids array is a null-terminated list of type_ids for the catch class
/// and all its descendants. For simplicity, we just check if exception_type_id
/// matches the given type_id.
#[no_mangle]
pub extern "C" fn picohp_eh_matches_type(catch_type_id: u32) -> i32 {
    unsafe {
        if EXCEPTION_STATE.exception_type_id == catch_type_id {
            return 1;
        }
        0
    }
}

/// Check if exception type matches any type in a list.
/// type_ids is a pointer to an array of u32, count is the length.
#[no_mangle]
pub extern "C" fn picohp_eh_matches_any(type_ids: *const u32, count: u32) -> i32 {
    unsafe {
        let slice = std::slice::from_raw_parts(type_ids, count as usize);
        for &tid in slice {
            if EXCEPTION_STATE.exception_type_id == tid {
                return 1;
            }
        }
        0
    }
}

/// Clear the current exception state (after it has been handled).
#[no_mangle]
pub extern "C" fn picohp_eh_clear() {
    unsafe {
        EXCEPTION_STATE.exception_ptr = std::ptr::null_mut();
        EXCEPTION_STATE.exception_type_id = 0;
    }
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
// Regex
// ---------------------------------------------------------------------------

/// PHP-compatible preg_match. Strips PCRE delimiters, runs regex, populates
/// matches array with captured groups. Returns 1 on match, 0 on no match.
#[no_mangle]
pub extern "C" fn pico_preg_match(
    pattern: *const c_char,
    subject: *const c_char,
    matches: *mut PicoArray,
) -> i32 {
    let pattern = unsafe { CStr::from_ptr(pattern) }.to_str().unwrap();
    let subject = unsafe { CStr::from_ptr(subject) }.to_str().unwrap();

    // Strip PHP PCRE delimiters: /pattern/flags -> pattern
    let regex_pattern = if pattern.starts_with('/') {
        let end = pattern.rfind('/').unwrap_or(pattern.len());
        if end > 0 { &pattern[1..end] } else { pattern }
    } else {
        pattern
    };

    let re = match Regex::new(regex_pattern) {
        Ok(r) => r,
        Err(_) => return 0,
    };

    match re.captures(subject) {
        Some(caps) => {
            let arr = unsafe { &mut *matches };
            arr.data.clear();
            for i in 0..caps.len() {
                let m = caps.get(i).map(|m| m.as_str()).unwrap_or("");
                let c_str = CString::new(m).unwrap();
                arr.data.push(PicoValue::Str(c_str.into_raw()));
            }
            1
        }
        None => 0,
    }
}

// ---------------------------------------------------------------------------
// String utility functions
// ---------------------------------------------------------------------------

/// Join array of strings with a separator.
#[no_mangle]
pub extern "C" fn pico_implode(glue: *const c_char, arr: *const PicoArray) -> *mut c_char {
    let glue = unsafe { CStr::from_ptr(glue) }.to_str().unwrap();
    let arr = unsafe { &*arr };
    let parts: Vec<&str> = arr.data.iter().map(|v| {
        match v {
            PicoValue::Str(s) => unsafe { CStr::from_ptr(*s) }.to_str().unwrap(),
            _ => "",
        }
    }).collect();
    let result = parts.join(glue);
    CString::new(result).unwrap().into_raw()
}

/// Convert string to uppercase.
#[no_mangle]
pub extern "C" fn pico_string_upper(s: *const c_char) -> *mut c_char {
    let s = unsafe { CStr::from_ptr(s) }.to_str().unwrap();
    let upper = s.to_uppercase();
    CString::new(upper).unwrap().into_raw()
}

/// Convert string to lowercase.
#[no_mangle]
pub extern "C" fn pico_string_lower(s: *const c_char) -> *mut c_char {
    let s = unsafe { CStr::from_ptr(s) }.to_str().unwrap();
    let lower = s.to_lowercase();
    CString::new(lower).unwrap().into_raw()
}

/// Convert integer to hexadecimal string.
#[no_mangle]
pub extern "C" fn pico_dechex(val: i32) -> *mut c_char {
    let s = format!("{:x}", val);
    CString::new(s).unwrap().into_raw()
}

/// Pad a string to a certain length. pad_type: 0=STR_PAD_LEFT, 1=STR_PAD_RIGHT.
#[no_mangle]
pub extern "C" fn pico_string_pad(s: *const c_char, length: i32, pad: *const c_char, pad_type: i32) -> *mut c_char {
    let s = unsafe { CStr::from_ptr(s) }.to_str().unwrap();
    let pad_str = unsafe { CStr::from_ptr(pad) }.to_str().unwrap();
    let length = length as usize;
    let result = if s.len() >= length {
        s.to_string()
    } else {
        let needed = length - s.len();
        let padding: String = pad_str.chars().cycle().take(needed).collect();
        if pad_type == 0 {
            // STR_PAD_LEFT
            format!("{}{}", padding, s)
        } else {
            // STR_PAD_RIGHT (1)
            format!("{}{}", s, padding)
        }
    };
    CString::new(result).unwrap().into_raw()
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

// -- array utility functions -------------------------------------------------

/// Search for an int value in an array. Returns index or -1 if not found.
#[no_mangle]
pub extern "C" fn pico_array_search_int(arr: *const PicoArray, val: i32) -> i32 {
    let arr = unsafe { &*arr };
    for (i, item) in arr.data.iter().enumerate() {
        if let PicoValue::Int(v) = item {
            if *v == val {
                return i as i32;
            }
        }
    }
    -1
}

/// Remove elements from an array at offset for length.
#[no_mangle]
pub extern "C" fn pico_array_splice(arr: *mut PicoArray, offset: i32, length: i32) {
    let arr = unsafe { &mut *arr };
    let offset = offset as usize;
    let length = length as usize;
    if offset < arr.data.len() {
        let end = (offset + length).min(arr.data.len());
        arr.data.drain(offset..end);
    }
}

/// Get the last element of an int array, or 0 if empty.
#[no_mangle]
pub extern "C" fn pico_array_last_int(arr: *const PicoArray) -> i32 {
    let arr = unsafe { &*arr };
    match arr.data.last() {
        Some(PicoValue::Int(v)) => *v,
        _ => 0,
    }
}

/// Get the last element of a string array, or empty string if empty.
#[no_mangle]
pub extern "C" fn pico_array_last_str(arr: *const PicoArray) -> *const c_char {
    let arr = unsafe { &*arr };
    match arr.data.last() {
        Some(PicoValue::Str(v)) => *v,
        _ => c"".as_ptr(),
    }
}

// ---------------------------------------------------------------------------
// String-keyed maps (associative arrays)
// ---------------------------------------------------------------------------

struct PicoMapEntry {
    key: *const c_char,
    value: PicoValue,
}

pub struct PicoMap {
    entries: Vec<PicoMapEntry>,
}

impl PicoMap {
    fn find_index(&self, key: *const c_char) -> Option<usize> {
        let needle = unsafe { CStr::from_ptr(key) }.to_bytes();
        self.entries.iter().position(|e| {
            unsafe { CStr::from_ptr(e.key) }.to_bytes() == needle
        })
    }
}

#[no_mangle]
pub extern "C" fn pico_map_new() -> *mut PicoMap {
    Box::into_raw(Box::new(PicoMap { entries: Vec::new() }))
}

#[no_mangle]
/// Check if a key exists in the map. Returns 1 if found, 0 otherwise.
#[no_mangle]
pub extern "C" fn pico_map_has_key(map: *const PicoMap, key: *const c_char) -> i32 {
    let map = unsafe { &*map };
    if map.find_index(key).is_some() { 1 } else { 0 }
}

#[no_mangle]
pub extern "C" fn pico_map_len(map: *const PicoMap) -> i32 {
    let map = unsafe { &*map };
    map.entries.len() as i32
}

// -- set (insert or update) -------------------------------------------------

#[no_mangle]
pub extern "C" fn pico_map_set_int(map: *mut PicoMap, key: *const c_char, val: i32) {
    let map = unsafe { &mut *map };
    if let Some(i) = map.find_index(key) {
        map.entries[i].value = PicoValue::Int(val);
    } else {
        map.entries.push(PicoMapEntry { key, value: PicoValue::Int(val) });
    }
}

#[no_mangle]
pub extern "C" fn pico_map_set_float(map: *mut PicoMap, key: *const c_char, val: f64) {
    let map = unsafe { &mut *map };
    if let Some(i) = map.find_index(key) {
        map.entries[i].value = PicoValue::Float(val);
    } else {
        map.entries.push(PicoMapEntry { key, value: PicoValue::Float(val) });
    }
}

#[no_mangle]
pub extern "C" fn pico_map_set_bool(map: *mut PicoMap, key: *const c_char, val: i32) {
    let map = unsafe { &mut *map };
    if let Some(i) = map.find_index(key) {
        map.entries[i].value = PicoValue::Bool(val != 0);
    } else {
        map.entries.push(PicoMapEntry { key, value: PicoValue::Bool(val != 0) });
    }
}

#[no_mangle]
pub extern "C" fn pico_map_set_str(map: *mut PicoMap, key: *const c_char, val: *const c_char) {
    let map = unsafe { &mut *map };
    if let Some(i) = map.find_index(key) {
        map.entries[i].value = PicoValue::Str(val);
    } else {
        map.entries.push(PicoMapEntry { key, value: PicoValue::Str(val) });
    }
}

#[no_mangle]
pub extern "C" fn pico_map_set_ptr(map: *mut PicoMap, key: *const c_char, val: *mut u8) {
    let map = unsafe { &mut *map };
    if let Some(i) = map.find_index(key) {
        map.entries[i].value = PicoValue::Ptr(val);
    } else {
        map.entries.push(PicoMapEntry { key, value: PicoValue::Ptr(val) });
    }
}

// -- get --------------------------------------------------------------------

#[no_mangle]
pub extern "C" fn pico_map_get_int(map: *const PicoMap, key: *const c_char) -> i32 {
    let map = unsafe { &*map };
    let i = map.find_index(key).expect("pico_map_get_int: key not found");
    match &map.entries[i].value {
        PicoValue::Int(v) => *v,
        _ => panic!("pico_map_get_int: value is not an int"),
    }
}

#[no_mangle]
pub extern "C" fn pico_map_get_float(map: *const PicoMap, key: *const c_char) -> f64 {
    let map = unsafe { &*map };
    let i = map.find_index(key).expect("pico_map_get_float: key not found");
    match &map.entries[i].value {
        PicoValue::Float(v) => *v,
        _ => panic!("pico_map_get_float: value is not a float"),
    }
}

#[no_mangle]
pub extern "C" fn pico_map_get_str(map: *const PicoMap, key: *const c_char) -> *const c_char {
    let map = unsafe { &*map };
    let i = map.find_index(key).expect("pico_map_get_str: key not found");
    match &map.entries[i].value {
        PicoValue::Str(v) => *v,
        _ => panic!("pico_map_get_str: value is not a string"),
    }
}

#[no_mangle]
pub extern "C" fn pico_map_get_ptr(map: *const PicoMap, key: *const c_char) -> *mut u8 {
    let map = unsafe { &*map };
    let i = map.find_index(key).expect("pico_map_get_ptr: key not found");
    match &map.entries[i].value {
        PicoValue::Ptr(v) => *v,
        _ => panic!("pico_map_get_ptr: value is not a pointer"),
    }
}

#[no_mangle]
pub extern "C" fn pico_map_get_bool(map: *const PicoMap, key: *const c_char) -> i32 {
    let map = unsafe { &*map };
    let i = map.find_index(key).expect("pico_map_get_bool: key not found");
    match &map.entries[i].value {
        PicoValue::Bool(v) => *v as i32,
        _ => panic!("pico_map_get_bool: value is not a bool"),
    }
}

// -- foreach support --------------------------------------------------------

/// Get the key at the given index (for ordered iteration).
#[no_mangle]
pub extern "C" fn pico_map_get_key(map: *const PicoMap, index: i32) -> *const c_char {
    let map = unsafe { &*map };
    map.entries[index as usize].key
}

/// Get int value at the given index (for ordered iteration).
#[no_mangle]
pub extern "C" fn pico_map_get_value_int(map: *const PicoMap, index: i32) -> i32 {
    let map = unsafe { &*map };
    match &map.entries[index as usize].value {
        PicoValue::Int(v) => *v,
        _ => panic!("pico_map_get_value_int: value is not an int"),
    }
}

/// Get string value at the given index (for ordered iteration).
#[no_mangle]
pub extern "C" fn pico_map_get_value_str(map: *const PicoMap, index: i32) -> *const c_char {
    let map = unsafe { &*map };
    match &map.entries[index as usize].value {
        PicoValue::Str(v) => *v,
        _ => panic!("pico_map_get_value_str: value is not a string"),
    }
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
    fn test_string_replace() {
        let search = CString::new("world").unwrap();
        let replace = CString::new("rust").unwrap();
        let subject = CString::new("hello world").unwrap();
        let r = pico_string_replace(search.as_ptr(), replace.as_ptr(), subject.as_ptr());
        assert_eq!(unsafe { CStr::from_ptr(r) }.to_str().unwrap(), "hello rust");

        // Multiple occurrences
        let search2 = CString::new("o").unwrap();
        let replace2 = CString::new("0").unwrap();
        let subject2 = CString::new("foobar").unwrap();
        let r2 = pico_string_replace(search2.as_ptr(), replace2.as_ptr(), subject2.as_ptr());
        assert_eq!(unsafe { CStr::from_ptr(r2) }.to_str().unwrap(), "f00bar");
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
    fn test_float_to_string() {
        let check = |val: f64, expected: &str| {
            let ptr = pico_float_to_string(val);
            let result = unsafe { CStr::from_ptr(ptr) }.to_str().unwrap();
            assert_eq!(result, expected, "pico_float_to_string({}) = {:?}, expected {:?}", val, result, expected);
        };
        check(3.14, "3.14");
        check(0.0, "0");
        check(100.0, "100");
        check(-2.5, "-2.5");
        check(1e10, "10000000000");
        check(0.1, "0.1");
        check(0.5, "0.5");
    }

    #[test]
    fn test_float_to_hex() {
        let ptr = pico_float_to_hex(3.14);
        let result = unsafe { CStr::from_ptr(ptr) }.to_str().unwrap();
        assert_eq!(result, "0x40091EB851EB851F");

        let ptr2 = pico_float_to_hex(0.0);
        let result2 = unsafe { CStr::from_ptr(ptr2) }.to_str().unwrap();
        assert_eq!(result2, "0x0000000000000000");
    }

    #[test]
    fn test_map_basic() {
        let map = pico_map_new();
        let k1 = CString::new("name").unwrap();
        let v1 = CString::new("Alice").unwrap();
        let k2 = CString::new("city").unwrap();
        let v2 = CString::new("NYC").unwrap();
        pico_map_set_str(map, k1.as_ptr(), v1.as_ptr());
        pico_map_set_str(map, k2.as_ptr(), v2.as_ptr());
        assert_eq!(pico_map_len(map), 2);
        let r1 = unsafe { CStr::from_ptr(pico_map_get_str(map, k1.as_ptr())) };
        assert_eq!(r1.to_str().unwrap(), "Alice");
        // Update existing key
        let v3 = CString::new("LA").unwrap();
        pico_map_set_str(map, k2.as_ptr(), v3.as_ptr());
        assert_eq!(pico_map_len(map), 2); // no new entry
        let r2 = unsafe { CStr::from_ptr(pico_map_get_str(map, k2.as_ptr())) };
        assert_eq!(r2.to_str().unwrap(), "LA");
    }

    #[test]
    fn test_map_int_values() {
        let map = pico_map_new();
        let k1 = CString::new("math").unwrap();
        let k2 = CString::new("english").unwrap();
        pico_map_set_int(map, k1.as_ptr(), 95);
        pico_map_set_int(map, k2.as_ptr(), 87);
        assert_eq!(pico_map_get_int(map, k1.as_ptr()), 95);
        assert_eq!(pico_map_get_int(map, k2.as_ptr()), 87);
    }

    #[test]
    fn test_map_iteration() {
        let map = pico_map_new();
        let k1 = CString::new("a").unwrap();
        let k2 = CString::new("b").unwrap();
        pico_map_set_int(map, k1.as_ptr(), 1);
        pico_map_set_int(map, k2.as_ptr(), 2);
        // Iterate in insertion order
        let key0 = unsafe { CStr::from_ptr(pico_map_get_key(map, 0)) };
        assert_eq!(key0.to_str().unwrap(), "a");
        assert_eq!(pico_map_get_value_int(map, 0), 1);
        let key1 = unsafe { CStr::from_ptr(pico_map_get_key(map, 1)) };
        assert_eq!(key1.to_str().unwrap(), "b");
        assert_eq!(pico_map_get_value_int(map, 1), 2);
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
