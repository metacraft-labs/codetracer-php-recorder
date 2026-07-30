#ifndef CODETRACER_TRACE_WRITER_H
#define CODETRACER_TRACE_WRITER_H

#include <stdint.h>
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

/* Opaque handle to the trace writer */
typedef void* trace_writer_t;

/* Backward-compatibility alias for the Rust FFI's struct-pointer type.
 * The PHP recorder's C source declares variables as `TraceWriterHandle*`;
 * the Nim FFI's `trace_writer_t` is a typedef'd `void*`, so we alias the
 * struct name to `void` and existing `TraceWriterHandle*` declarations
 * resolve to `void*` — same memory layout as `trace_writer_t`. */
typedef void TraceWriterHandle;

/* --------------------------------------------------------------------------
 * Backward-compatibility aliases for the names used by the original
 * Rust-FFI-shaped header.  The PHP recorder's C source was written
 * against `FMT_*`, `TK_*` and `ELK_*`; the Nim FFI uses the longer
 * `FFI_TRACE_FORMAT_*`, `FFI_TYPE_*` and `FFI_EVENT_*` names.  Rather
 * than edit every call site in `codetracer_php.c` we expose the
 * shorter aliases here.  Note that the *enum constants* are the same
 * integers in both libraries; only the symbolic names differ.
 *
 * These aliases match the legacy `enum Fmt`, `enum Tk`, `enum Elk`
 * declarations that the Rust cbindgen-generated header used.
 * -------------------------------------------------------------------------- */
#define FMT_JSON               0
#define FMT_BINARY_V0          1
#define FMT_BINARY             2
#define FMT_CTFS               2 /* Nim FFI's "binary" *is* CTFS V4 multi-stream */

#define TK_SEQ                 0
#define TK_SET                 1
#define TK_HASH_SET            2
#define TK_ORDERED_SET         3
#define TK_ARRAY               4
#define TK_VARARGS             5
#define TK_STRUCT              6
#define TK_INT                 7
#define TK_FLOAT               8
#define TK_STRING              9
#define TK_CSTRING             10
#define TK_CHAR                11
#define TK_BOOL                12
#define TK_LITERAL             13
#define TK_REF                 14
#define TK_RECURSION           15
#define TK_RAW                 16
#define TK_ENUM                17
#define TK_ENUM16              18
#define TK_ENUM32              19
#define TK_C                   20
#define TK_TABLE_KIND          21
#define TK_UNION               22
#define TK_POINTER             23
#define TK_ERROR               24
#define TK_FUNCTION_KIND       25
#define TK_TYPE_VALUE          26
#define TK_TUPLE               27
#define TK_VARIANT             28
#define TK_HTML                29
#define TK_NONE                30
#define TK_NON_EXPANDED        31
#define TK_ANY                 32
#define TK_SLICE               33

#define ELK_WRITE              0
#define ELK_WRITE_FILE         1
#define ELK_WRITE_OTHER        2
#define ELK_READ               3
#define ELK_READ_FILE          4
#define ELK_READ_OTHER         5
#define ELK_READ_DIR           6
#define ELK_OPEN_DIR           7
#define ELK_CLOSE_DIR          8
#define ELK_SOCKET             9
#define ELK_OPEN               10
#define ELK_ERROR              11
#define ELK_TRACE_LOG_EVENT    12
#define ELK_EVM_EVENT          13

/* --------------------------------------------------------------------------
 * FFI enums (must match Rust codetracer_trace_writer_ffi repr(C) values)
 * -------------------------------------------------------------------------- */

enum FfiTraceFormat {
    FFI_TRACE_FORMAT_JSON = 0,
    FFI_TRACE_FORMAT_BINARY_V0 = 1,
    FFI_TRACE_FORMAT_BINARY = 2
};

enum FfiTypeKind {
    FFI_TYPE_SEQ = 0,
    FFI_TYPE_SET = 1,
    FFI_TYPE_HASH_SET = 2,
    FFI_TYPE_ORDERED_SET = 3,
    FFI_TYPE_ARRAY = 4,
    FFI_TYPE_VARARGS = 5,
    FFI_TYPE_STRUCT = 6,
    FFI_TYPE_INT = 7,
    FFI_TYPE_FLOAT = 8,
    FFI_TYPE_STRING = 9,
    FFI_TYPE_CSTRING = 10,
    FFI_TYPE_CHAR = 11,
    FFI_TYPE_BOOL = 12,
    FFI_TYPE_LITERAL = 13,
    FFI_TYPE_REF = 14,
    FFI_TYPE_RECURSION = 15,
    FFI_TYPE_RAW = 16,
    FFI_TYPE_ENUM = 17,
    FFI_TYPE_ENUM16 = 18,
    FFI_TYPE_ENUM32 = 19,
    FFI_TYPE_C = 20,
    FFI_TYPE_TABLE_KIND = 21,
    FFI_TYPE_UNION = 22,
    FFI_TYPE_POINTER = 23,
    FFI_TYPE_ERROR = 24,
    FFI_TYPE_FUNCTION_KIND = 25,
    FFI_TYPE_TYPE_VALUE = 26,
    FFI_TYPE_TUPLE = 27,
    FFI_TYPE_VARIANT = 28,
    FFI_TYPE_HTML = 29,
    FFI_TYPE_NONE = 30,
    FFI_TYPE_NON_EXPANDED = 31,
    FFI_TYPE_ANY = 32,
    FFI_TYPE_SLICE = 33
};

enum FfiEventLogKind {
    FFI_EVENT_WRITE = 0,
    FFI_EVENT_WRITE_FILE = 1,
    FFI_EVENT_WRITE_OTHER = 2,
    FFI_EVENT_READ = 3,
    FFI_EVENT_READ_FILE = 4,
    FFI_EVENT_READ_OTHER = 5,
    FFI_EVENT_READ_DIR = 6,
    FFI_EVENT_OPEN_DIR = 7,
    FFI_EVENT_CLOSE_DIR = 8,
    FFI_EVENT_SOCKET = 9,
    FFI_EVENT_OPEN = 10,
    FFI_EVENT_ERROR = 11,
    FFI_EVENT_TRACE_LOG_EVENT = 12,
    FFI_EVENT_EVM_EVENT = 13
};

/* --------------------------------------------------------------------------
 * Initialization (call once before using any other function)
 * -------------------------------------------------------------------------- */

void codetracer_trace_writer_init(void);

/* --------------------------------------------------------------------------
 * Error handling
 * -------------------------------------------------------------------------- */

const char* trace_writer_last_error(void);

/* --------------------------------------------------------------------------
 * Lifecycle
 * -------------------------------------------------------------------------- */

trace_writer_t trace_writer_new(const char* program, int format);
void trace_writer_free(trace_writer_t handle);
int trace_writer_close(trace_writer_t handle);

/* --------------------------------------------------------------------------
 * File I/O — begin / finish (compatibility with Rust API)
 * -------------------------------------------------------------------------- */

int trace_writer_begin_metadata(trace_writer_t handle, const char* path);
int trace_writer_finish_metadata(trace_writer_t handle);
int trace_writer_begin_events(trace_writer_t handle, const char* path);
int trace_writer_finish_events(trace_writer_t handle);
int trace_writer_begin_paths(trace_writer_t handle, const char* path);
int trace_writer_finish_paths(trace_writer_t handle);

/* --------------------------------------------------------------------------
 * Tracing primitives
 * -------------------------------------------------------------------------- */

void trace_writer_start(trace_writer_t handle, const char* path, int64_t line);
void trace_writer_set_workdir(trace_writer_t handle, const char* workdir);
void trace_writer_register_step(trace_writer_t handle,
                                const char* path, int64_t line);

size_t trace_writer_ensure_function_id(trace_writer_t handle,
    const char* name, const char* path, int64_t line);

size_t trace_writer_ensure_type_id(trace_writer_t handle,
    int kind, const char* lang_type);

void trace_writer_register_call(trace_writer_t handle, size_t function_id);
void trace_writer_register_return(trace_writer_t handle);

void trace_writer_register_return_int(trace_writer_t handle,
                                      int64_t value,
                                      int type_kind,
                                      const char* type_name);

void trace_writer_register_return_raw(trace_writer_t handle,
                                      const char* value_repr,
                                      int type_kind,
                                      const char* type_name);

void trace_writer_register_variable_int(trace_writer_t handle,
                                        const char* name,
                                        int64_t value,
                                        int type_kind,
                                        const char* type_name);

void trace_writer_register_variable_raw(trace_writer_t handle,
                                        const char* name,
                                        const char* value_repr,
                                        int type_kind,
                                        const char* type_name);

void trace_writer_register_variable_cbor(trace_writer_t handle,
    const char* name,
    const uint8_t* cbor_data,
    size_t cbor_len);

void trace_writer_register_return_cbor(trace_writer_t handle,
    const uint8_t* cbor_data,
    size_t cbor_len);

void trace_writer_register_special_event(trace_writer_t handle,
    int kind, const char* metadata, const char* content);

/* --------------------------------------------------------------------------
 * Request / interval spans (RS-M1)
 *
 * A span is a bounded, labeled interval of execution — an HTTP request, a
 * process, a test — appended to the container's `spans.dat` stream.  This is
 * what the PHP recorder emits instead of the `session_manifest.jsonl` sidecar
 * it used to write next to a per-request trace directory (RS-M7).
 *
 * Declarations mirror `codetracer-trace-format-nim/include/
 * codetracer_trace_writer.h`.  They are re-declared here rather than included
 * because this header is the tracked alias shim (see the FMT_* / TK_* / ELK_*
 * block above) — see ext/build.sh for why the upstream header is not copied
 * over this file.
 * -------------------------------------------------------------------------- */

/* `flags` bits */
#define SPAN_FLAG_OPEN     0x01u  /* open record; completion still to come */
#define SPAN_FLAG_EXTERNAL 0x02u  /* execution lives in another container */

/* `status` values */
#define SPAN_STATUS_UNKNOWN 0u
#define SPAN_STATUS_OK      1u
#define SPAN_STATUS_ERROR   2u

/* `structural` bits (Trace-Spans.md 2.4) */
#define SPAN_STRUCTURAL_CONTIGUOUS      0x01u /* uninterrupted, one thread   */
#define SPAN_STRUCTURAL_SHARES_TIMELINE 0x02u /* ordering comparable         */
#define SPAN_STRUCTURAL_CONCURRENT      0x04u /* siblings may overlap        */

/*
 * external_recording / external_path are read ONLY when SPAN_FLAG_EXTERNAL is
 * set (pass NULL otherwise).  metadata_keys / metadata_values are parallel
 * arrays of NUL-terminated UTF-8 strings of length metadata_count; their ORDER
 * IS PRESERVED end to end, so emit the well-known HTTP keys in display order.
 * Returns 0 on success, non-zero on failure (see trace_writer_last_error).
 */
int trace_writer_register_span(trace_writer_t handle,
    uint64_t span_id,
    uint64_t parent_span_id,
    uint8_t flags,
    uint8_t status,
    uint64_t start_wall_ns,
    uint64_t end_wall_ns,
    uint64_t process_ord,
    uint64_t thread_id,
    uint64_t start_step,
    uint64_t end_step,
    const char* external_recording,
    const char* external_path,
    const char* span_type,
    const char* label,
    uint8_t structural,
    const char** metadata_keys,
    const char** metadata_values,
    size_t metadata_count);

/* Seal the current partial span chunk without closing the writer. */
int trace_writer_flush_spans(trace_writer_t handle);

/*
 * The exec-stream index the NEXT event registered on this writer will occupy —
 * the `start_step` a span opened right now should carry.  A span that runs from
 * here to there is `start_step = trace_writer_next_step_index()` at entry and
 * `end_step = trace_writer_next_step_index() - 1` at exit (clamped to
 * `start_step` when nothing was recorded in between).
 *
 * This is the writer's own step counter, NOT a count of
 * trace_writer_register_step calls: the counter advances for every exec-stream
 * event (absolute steps, DeltaColumn column moves, raise / catch, thread
 * start / exit / switch), and that counter is the step id every reader walks.
 * A recorder counting its own register_step calls would drift the moment it
 * emitted a column delta or a thread event.
 */
uint64_t trace_writer_next_step_index(trace_writer_t handle);

/*
 * Decode the span stream of the `.ct` container at `path` into a JSON array —
 * the READ counterpart of trace_writer_register_span.  `settled != 0` applies
 * last-record-wins per span_id and sorts ascending by span_id; `settled == 0`
 * returns every record in append order, open records included.  Free with
 * ct_free_buffer.
 */
uint8_t* ct_spans_json(const char* path, int settled, size_t* out_len);

/* --------------------------------------------------------------------------
 * Thread lifecycle events
 *
 * Recorders that observe multi-threaded program execution emit ThreadStart /
 * ThreadExit / ThreadSwitch through these entry points.  Earlier versions of
 * the Nim backend dropped these events when they came in via the Rust shim's
 * ``TraceWriter::add_event(TraceLowLevelEvent::ThreadStart{,Exit,Switch})``
 * dispatch — see incidents 1.21 / 1.22 / 1.27.
 * -------------------------------------------------------------------------- */

void trace_writer_register_thread_start(trace_writer_t handle, uint64_t thread_id);
void trace_writer_register_thread_exit(trace_writer_t handle, uint64_t thread_id);
void trace_writer_register_thread_switch(trace_writer_t handle, uint64_t thread_id);

/* --------------------------------------------------------------------------
 * meta.dat — write via trace writer handle
 * -------------------------------------------------------------------------- */

int ct_write_meta_dat(trace_writer_t handle,
                      const uint8_t* recorder_id, size_t recorder_id_len);

/* --------------------------------------------------------------------------
 * meta.dat — standalone buffer write
 * -------------------------------------------------------------------------- */

int ct_write_meta_dat_to_buffer(
    const uint8_t* program, size_t program_len,
    const uint8_t* workdir, size_t workdir_len,
    const uint8_t* const* args, const size_t* arg_lens, size_t args_count,
    const uint8_t* const* paths, const size_t* path_lens, size_t paths_count,
    const uint8_t* recorder_id, size_t recorder_id_len,
    uint8_t** out_buf, size_t* out_len);

void ct_free_buffer(uint8_t* buf);

/* --------------------------------------------------------------------------
 * meta.dat — reader handle
 * -------------------------------------------------------------------------- */

typedef void* meta_dat_reader_t;

meta_dat_reader_t ct_read_meta_dat(const uint8_t* data, size_t len);
const uint8_t* ct_meta_dat_program(meta_dat_reader_t h, size_t* out_len);
const uint8_t* ct_meta_dat_workdir(meta_dat_reader_t h, size_t* out_len);
size_t ct_meta_dat_args_count(meta_dat_reader_t h);
const uint8_t* ct_meta_dat_arg(meta_dat_reader_t h, size_t idx, size_t* out_len);
size_t ct_meta_dat_paths_count(meta_dat_reader_t h);
const uint8_t* ct_meta_dat_path(meta_dat_reader_t h, size_t idx, size_t* out_len);
const uint8_t* ct_meta_dat_recorder_id(meta_dat_reader_t h, size_t* out_len);
void ct_meta_dat_free(meta_dat_reader_t h);

/* --------------------------------------------------------------------------
 * Streaming value encoder (zero-allocation CBOR)
 * -------------------------------------------------------------------------- */

typedef void* value_encoder_t;

value_encoder_t ct_value_encoder_new(void);
void ct_value_encoder_free(value_encoder_t h);
void ct_value_encoder_reset(value_encoder_t h);

int ct_value_write_int(value_encoder_t h, int64_t value, uint64_t type_id);
int ct_value_write_float(value_encoder_t h, double value, uint64_t type_id);
int ct_value_write_bool(value_encoder_t h, int value);
int ct_value_write_bool_typed(value_encoder_t h, int value, uint64_t type_id);
int ct_value_write_string(value_encoder_t h, const uint8_t* data, size_t len, uint64_t type_id);
int ct_value_write_none(value_encoder_t h);
int ct_value_write_none_typed(value_encoder_t h, uint64_t type_id);
int ct_value_write_raw(value_encoder_t h, const uint8_t* data, size_t len, uint64_t type_id);
int ct_value_write_error(value_encoder_t h, const uint8_t* data, size_t len, uint64_t type_id);

int ct_value_begin_struct(value_encoder_t h, uint64_t type_id, int field_count);
int ct_value_begin_sequence(value_encoder_t h, uint64_t type_id, int element_count);
int ct_value_begin_tuple(value_encoder_t h, uint64_t type_id, int element_count);
int ct_value_end_compound(value_encoder_t h);

const uint8_t* ct_value_get_bytes(value_encoder_t h, size_t* out_len);

#ifdef __cplusplus
}
#endif

#endif /* CODETRACER_TRACE_WRITER_H */
