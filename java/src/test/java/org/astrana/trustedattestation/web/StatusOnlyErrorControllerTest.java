package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.Mockito.when;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.request;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import jakarta.servlet.DispatcherType;
import jakarta.servlet.RequestDispatcher;
import jakarta.servlet.http.HttpServletRequest;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;
import org.springframework.http.HttpMethod;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;

/**
 * The framework's own errors, answered with a status and no body.
 *
 * <p>Boot's default error controller renders a JSON object for a framework-raised 404 or 405; the
 * contract does not allow a body on an error, so this replacement strips it. What is pinned here is that
 * the code the framework recorded is carried through, that it is never accompanied by a body, and that the
 * two ways of having no usable code -- none recorded, and one that resolves to no known status -- both
 * become a plain 500 rather than throwing while trying to report an error.
 */
@ExtendWith(MockitoExtension.class)
class StatusOnlyErrorControllerTest {

    @Mock
    private HttpServletRequest request;

    private final StatusOnlyErrorController controller = new StatusOnlyErrorController();

    @Test
    void carriesTheFrameworkRecordedStatusThroughWithNoBody() {
        // A wrong method reaches /error with 405 recorded; the answer is exactly that status, and -- the
        // reason this controller exists -- an empty body where Boot would have put a JSON object.
        when(request.getAttribute(RequestDispatcher.ERROR_STATUS_CODE)).thenReturn(405);

        ResponseEntity<Void> response = controller.handleError(request);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.METHOD_NOT_ALLOWED);
        assertThat(response.getBody()).isNull();
    }

    @Test
    void aMissingStatusCodeFallsBackToInternalServerError() {
        // No code recorded at all -- the controller must still answer, and 500 is the honest default rather
        // than a NullPointerException raised while handling an error.
        when(request.getAttribute(RequestDispatcher.ERROR_STATUS_CODE)).thenReturn(null);

        assertThat(controller.handleError(request).getStatusCode()).isEqualTo(HttpStatus.INTERNAL_SERVER_ERROR);
    }

    @Test
    void anUnrecognisedStatusNumberFallsBackToInternalServerError() {
        // A code that is an Integer but not a status HttpStatus.resolve knows returns null; that second,
        // easily-missed path must land on 500 too, not pass null down as the response status.
        when(request.getAttribute(RequestDispatcher.ERROR_STATUS_CODE)).thenReturn(799);

        assertThat(controller.handleError(request).getStatusCode()).isEqualTo(HttpStatus.INTERNAL_SERVER_ERROR);
    }

    @ParameterizedTest
    @ValueSource(strings = {"GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"})
    void anErrorOnAnyMethodIsAnsweredWithItsStatusAndNoBody(String method) throws Exception {
        // The container forwards a failed request to /error with its method unchanged, so a failed POST or
        // DELETE needs the same status-only answer as a failed GET. A 400 is recorded because a method the
        // mapping missed would be answered 405, or 200 for OPTIONS, and so could not pass for it. TRACE is
        // left out: the dispatcher servlet writes its own echo of a TRACE request whatever the mapping says.
        MockMvc mvc = MockMvcBuilders.standaloneSetup(controller).build();

        mvc.perform(request(HttpMethod.valueOf(method), "/error")
                        .requestAttr(RequestDispatcher.ERROR_STATUS_CODE, 400)
                        .with(errorDispatch -> {
                            errorDispatch.setDispatcherType(DispatcherType.ERROR);
                            return errorDispatch;
                        }))
                .andExpect(status().isBadRequest())
                .andExpect(content().string(""));
    }
}
