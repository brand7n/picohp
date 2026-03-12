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

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::CString;

    #[test]
    fn test_version() {
        assert_eq!(pico_rt_version(), 1);
    }

    #[test]
    fn test_string_concat() {
        let a = CString::new("Hello").unwrap();
        let b = CString::new(" World").unwrap();
        let result = pico_string_concat(a.as_ptr(), b.as_ptr());
        let result_str = unsafe { CStr::from_ptr(result) };
        assert_eq!(result_str.to_str().unwrap(), "Hello World");
    }
}
