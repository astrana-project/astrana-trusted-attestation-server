package org.astrana.trustedattestation.web;

import org.astrana.trustedattestation.contract.Attribution;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

/**
 * The software's own licence, at the fixed public path the footer links to (see {@link Attribution}).
 *
 * <p>Anonymous, like the landing page, because a licence is public. Its content is the same attribution the
 * whole application already carries, the project name, the licence notice, the MIT License text and the
 * trademark notice from the contract's {@code attribution.json}, so there is one source of truth and nothing
 * here to keep in step by hand.
 *
 * <p>Unlike the landing page it is not localised and carries no organisation branding. It is a statement
 * about the software, not about the organisation running the instance, so it renders in English with a
 * fixed text direction and no language switcher. That is also what lets it read the same across all three
 * implementations.
 */
@Controller
public class LicenseController {

    private final Attribution attribution;

    public LicenseController(Attribution attribution) {
        this.attribution = attribution;
    }

    @GetMapping("/license")
    public String license(Model model) {
        // The same object the landing footer already consumes: project name and URL, and the licence
        // notice and its optional URL. The template reads projectName and licenseNotice for the card, and
        // the whole of it again for the footer every page shows.
        model.addAttribute("attribution", attribution);

        return "license";
    }
}
