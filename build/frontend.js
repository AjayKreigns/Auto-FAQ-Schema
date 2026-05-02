document.addEventListener("DOMContentLoaded", function () {
    "use strict";
    var containers = document.querySelectorAll(".auto-faq-container.auto-faq-layout-accordion");
    containers.forEach(function (container) {
        var questions = container.querySelectorAll(".auto-faq-question"),
            allowMultiple = container.dataset.allowMultiple === "true",
            trackAnalytics = container.dataset.trackAnalytics !== "false";
            
        questions.forEach(function (question) {
            question.addEventListener("click", function () {
                var isExpanded = this.getAttribute("aria-expanded") === "true",
                    answer = this.nextElementSibling;
                    
                if (trackAnalytics && !isExpanded) {
                    var qText = this.dataset.question;
                    qText && fetch(autoFaqData.ajaxUrl, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            action: "auto_faq_track_click",
                            question: qText,
                            page_url: autoFaqData.pageUrl,
                            page_title: autoFaqData.pageTitle,
                            nonce: autoFaqData.nonce
                        })
                    });
                }
                
                if (!allowMultiple && !isExpanded) {
                    questions.forEach(function (otherQuestion) {
                        if (otherQuestion !== this) {
                            var wasExpanded = otherQuestion.getAttribute("aria-expanded") === "true";
                            if (wasExpanded) {
                                otherQuestion.setAttribute("aria-expanded", "false");
                                var otherAnswer = otherQuestion.nextElementSibling;
                                
                                // Explicitly set current height to allow reverse transition from 'none'
                                otherAnswer.style.maxHeight = otherAnswer.scrollHeight + "px";
                                void otherAnswer.offsetHeight; // Force reflow
                                
                                otherAnswer.classList.remove("is-open");
                                otherAnswer.style.maxHeight = null;
                                setTimeout(function () {
                                    otherAnswer.hidden = true;
                                }, 400);
                            }
                        }
                    }.bind(this));
                }
                
                if (isExpanded) {
                    this.setAttribute("aria-expanded", "false");
                    
                    // Explicitly set current height to allow reverse transition from 'none'
                    answer.style.maxHeight = answer.scrollHeight + "px";
                    void answer.offsetHeight; // Force reflow
                    
                    answer.classList.remove("is-open");
                    answer.style.maxHeight = null;
                    setTimeout(function () {
                        answer.hidden = true;
                    }, 400);
                } else {
                    answer.hidden = false;
                    void answer.offsetHeight; // Force reflow to ensure CSS transition runs
                    
                    answer.classList.add("is-open");
                    var scrollHeight = answer.scrollHeight;
                    answer.style.maxHeight = scrollHeight + "px";
                    
                    // After transition completes, set max-height to none to prevent cutoff on resize
                    setTimeout(function() {
                        if (answer.classList.contains("is-open")) {
                            answer.style.maxHeight = "none";
                        }
                    }, 400);
                }
                
                this.setAttribute("aria-expanded", !isExpanded);
            });
        });
    });
});
