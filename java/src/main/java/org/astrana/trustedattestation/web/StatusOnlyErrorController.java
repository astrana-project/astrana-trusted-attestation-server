package org.astrana.trustedattestation.web;

import static org.springframework.web.bind.annotation.RequestMethod.DELETE;
import static org.springframework.web.bind.annotation.RequestMethod.GET;
import static org.springframework.web.bind.annotation.RequestMethod.HEAD;
import static org.springframework.web.bind.annotation.RequestMethod.OPTIONS;
import static org.springframework.web.bind.annotation.RequestMethod.PATCH;
import static org.springframework.web.bind.annotation.RequestMethod.POST;
import static org.springframework.web.bind.annotation.RequestMethod.PUT;
import static org.springframework.web.bind.annotation.RequestMethod.TRACE;

import jakarta.servlet.RequestDispatcher;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.boot.webmvc.error.ErrorController;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

/**
 * Errors carry a status code and nothing else -- no body.
 *
 * <p>Replaces Boot's default error controller, which renders a JSON object ({@code timestamp}, {@code
 * status}, {@code error}, {@code path}) for framework-raised errors like a 405 or a 404. That is a body
 * on an error, which the contract does not allow: response bodies are for data. The endpoints already
 * answer their own errors with an empty {@code ResponseEntity}; this closes the one path they do not
 * reach -- the errors the framework raises before or around the handler -- so a wrong method or an
 * unmatched route answers exactly as the .NET implementation does, a status and no more.
 */
@RestController
class StatusOnlyErrorController implements ErrorController {

    // Maps every method, each one named. This is Boot's error dispatch target, and an error can arise from
    // a request of any method, so restricting it to GET would drop the body-less status on a failed POST or
    // PUT. Naming them all keeps the behaviour of the unrestricted mapping this replaced.
    @RequestMapping(
            value = "/error",
            method = {GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, TRACE})
    ResponseEntity<Void> handleError(HttpServletRequest request) {
        Object code = request.getAttribute(RequestDispatcher.ERROR_STATUS_CODE);
        HttpStatus status = code instanceof Integer value ? HttpStatus.resolve(value) : null;

        return ResponseEntity.status(status != null ? status : HttpStatus.INTERNAL_SERVER_ERROR)
                .build();
    }
}
